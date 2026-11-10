<?php
/**
 * wa_prod.php — product knowledge from Shopify for the WhatsApp console ("Find a perfume", photo sending, bot suggestions).
 * Loaded by wa_brain.php. Everything is local after a sync, so the chat sheet answers in milliseconds.
 *
 *   wa.prod_search     GET   {q, kind?, limit?, ids?}          staff search (real name, website name, our name) / occasion words
 *   wa.prod_suggest    GET   {q|occasion, n?}                  top picks for a request ("winter office")
 *   wa.prod_attached   GET   {phone}                           pictures the prepared reply should carry (max 3 in all) for the newest customer message: products[] (old), lib[] (Photos tab), items[] (both)
 *   wa.prod_send       POST  {phone, ids[], mode, force?, lib_ids?}  send photo(s) into a chat: ids = product ids or 'lib:<ph id>' (Photos tab, via the media library)
 *   wa.prod_try        GET  q=<customer message>, reply=<bot text>   read-only preview for the Teach practice chat: {products, lib, items, flag, why}   (writes nothing)
 *   wa.prod_status     GET                                     counts, last sync, gaps, request types (Products tab)           [admin]
 *   wa.prod_sync       POST  {enrich?}                         "Refresh now"                                                    [admin]
 *   wa.prod_occ_list   GET                                     request types with hits (chips in the sheet)
 *   wa.prod_occ_toggle POST  {slug, active}                    switch a request type on / off                                   [admin]
 *   wa.prod_enrich     POST                                     run one AI batch now                                             [admin]
 *   wa.prod_auto       POST  {on}                               switch the 06:30 morning refresh on / off (default on)           [admin]
 *
 * Other code may call:  wa_prod_pick($pdo, $query, $n = 3, $opt = [])   wa_prod_last_asked($pdo, $phone)   wa_prod_caption($p, $mode)
 *                       wa_send_image_link($pdo, $c, $url, $caption, $who, $empId, $prodId)   wa_prod_daily($pdo, $opt)
 * Customer-facing text only ever uses the website spelling (original_site). The real original (original_real) is staff-only.
 */
if (!function_exists('wa_prod_tables')) {

/** Shopify metafield keys in one place — change here if the store uses other keys. [namespace, key] */
function wa_prod_mf(): array
{
    return ['notes' => ['custom', 'notes'], 'longevity' => ['custom', 'longevity'], 'gender' => ['custom', 'gender'], 'scent_profile' => ['custom', 'scent_profile'],
        'inspiredby' => ['custom', 'inspiredby'], 'perfumename' => ['custom', 'perfumename'], 'tmgender' => ['custom', 'tmgender'], 'best_for' => ['custom', 'best_for'],
        'units_sold' => ['custom', 'units_sold'], 'categories' => ['custom', 'shoy_by_category'], 'our_price' => ['custom', 'popup_our_price'], 'our_ml' => ['custom', 'popup_our_ml'],
        'rating' => ['reviews', 'rating'], 'rating_count' => ['reviews', 'rating_count'], 'season' => ['shopify', 'season'], 'perfume_notes' => ['custom', 'perfume-notes']];
}
/** House abbreviations used in our product names (staff search only — never written to customers). */
function wa_prod_houses(): array
{
    return ['crid' => 'Creed', 'dr' => 'Dior', 'tmfd' => 'Tom Ford', 'ver' => 'Versace', 'arm' => 'Armani', 'azz' => 'Azzaro', 'nish' => 'Nishane', 'nsn' => 'Nishane', 'viktori' => 'Viktor Rolf', 'viktoria' => 'Viktor Rolf',
        'pako' => 'Paco Rabanne', 'loui' => 'Louis Vuitton', 'kln' => 'Kilian', 'crln' => 'Carolina Herrera', 'montee' => 'Montblanc', 'hg' => 'Hugo Boss', 'jpege' => 'Jean Paul Gaultier', 'yyls' => 'Yves Saint Laurent',
        'prava' => 'Prada', 'bcara' => 'Baccarat', 'mncra' => 'Mancera', 'vlntn' => 'Valentino', 'chnl' => 'Chanel', 'joma' => 'Jo Malone', 'byrd' => 'Byredo', 'rj' => 'Roja', 'clav' => 'Calvin Klein', 'rasa' => 'Rasasi',
        'iss' => 'Issey Miyake', 'dolche' => 'Dolce Gabbana'];
}
/** Note families — used for the rule-based fit, the "why" line and similar-product matching. */
function wa_prod_families(): array
{
    return [
        'citrus' => 'lemon|lime|orange|bergamot|grapefruit|mandarin|yuzu|citrus|tangerine|lemongrass|citron|cedrat|pomelo',
        'aquatic' => '\bsea\b|marine|aqua|water|ozon|ocean|watery|calone|salt|seaweed|\bmist\b',
        'fresh' => 'mint|lavender|green|aromatic|cucumber|\btea\b|fresh|eucalyptus|rosemary|sage|basil|menthol|ginger|cool|clean|herbal',
        'woody' => 'cedar|sandal|vetiver|patchouli|wood|\boak\b|pine|cypress|guaiac|birch|moss|papyrus',
        'oud' => '\boud|agarwood|\bagar\b',
        'spicy' => 'pepper|cinnamon|cardamom|clove|saffron|nutmeg|spice|cumin|anise|incense|coriander|spicy',
        'sweet' => 'vanill|caramel|honey|sugar|praline|tonka|chocolate|coffee|candy|marshmallow|almond|coconut|cream|toffee|gourmand|sweet|cacao|dessert',
        'floral' => 'rose|jasmin|lily|peony|freesia|violet|iris|orchid|tuberose|ylang|magnolia|gardenia|flower|floral|lotus|neroli|blossom|mimosa|lilac',
        'fruity' => 'apple|pear|plum|peach|berry|berries|raspberry|strawberry|currant|cherry|mango|pineapple|melon|\bfig\b|fruit|passion|lychee|grape|apricot|banana|pomegranate',
        'amber' => 'amber|musk|ambroxan|ambergris|labdanum|benzoin|resin|myrrh|balsam',
        'leather' => 'leather|tobacco|suede|smok|\brum\b|whisk|cognac|tar\b',
    ];
}
/** Adjectives for the "why" line. */
function wa_prod_adj(): array
{
    return ['citrus' => 'citrusy', 'aquatic' => 'aquatic', 'fresh' => 'fresh', 'woody' => 'woody', 'oud' => 'deep oud', 'spicy' => 'spicy', 'sweet' => 'sweet', 'floral' => 'floral', 'fruity' => 'fruity', 'amber' => 'warm', 'leather' => 'smoky'];
}
/** Request types: slug => [label, group, keywords (regex alternatives), site category names that map to it]. Group 'who' = a filter, 'season', 'occ', 'scent', 'quality'. */
function wa_prod_occ_defaults(): array
{
    return [
        'winter' => ['Winter', 'season', 'winter|sardi|sardiyo|thand|thandi|cold weather|snow', ['winter fragrances']],
        'summer' => ['Summer', 'season', 'summer|garmi|garmiyo|hot weather|heat|humid|pasina|sweat proof', []],
        'spring' => ['Spring', 'season', 'spring|vasant|basant', []],
        'fall' => ['Fall', 'season', 'fall|autumn|monsoon|barish|baarish|rainy|rain', []],
        'men' => ['For men', 'who', 'for men|mens|men s|men|man|male|for him|him|for boys?|boys?|husband|boyfriend|papa|father|brother|gents|pati', []],
        'women' => ['For women', 'who', 'for women|womens|women s|women|woman|female|for her|ladies|lady|girls?|wife|girlfriend|biwi|bahan|sister|mummy|mother|maa|her|patni|gf', []],
        'unisex' => ['Unisex', 'who', 'unisex|both|dono', []],
        'office' => ['Office', 'occ', 'office|work|formal|corporate|9 to 5|meeting|professional', ['daily office men', 'daily office women', 'daily office']],
        'date-night' => ['Date night', 'occ', 'date night|romantic|romance|candle light|couple|anniversary|valentine|evening|dinner|date', ['evening date night']],
        'party' => ['Party', 'occ', 'night ?out|nightlife|party|club|pub|dj|disco|celebration', ['party nightout']],
        'wedding' => ['Wedding', 'occ', 'wedding|shaadi|shadi|marriage|sangeet|reception|engagement|mehendi|mehndi|haldi|baraat|function', []],
        'gift' => ['Gift', 'occ', 'return gift|gifting|gift|present|tohfa|birthday|surprise|rakhi|diwali|hamper', []],
        'best-sellers' => ['Best sellers', 'quality', 'best sell\w*|bestsell\w*|top sell\w*|popular|trending|most sold|hot selling|famous|number 1|no 1|top rated|best rated', ['best sellers men', 'best sellers women', 'best sellers']],
        'long-lasting' => ['Long lasting', 'quality', 'long lasting|longlasting|long last\w*|lasting|strong|projection|beast mode|sillage|all day|tikta|tikne wala|durable|stays', ['super long lasting fragrances', 'super long lasting']],
        'fresh' => ['Fresh', 'scent', 'fresh|light|halka|clean|cool|aquatic|crisp|citrus|citrusy|refreshing', ['fresh summer']],
        'sweet' => ['Sweet', 'scent', 'sweet|vanilla|gourmand|sugar|mitha|candy|dessert', []],
        'woody' => ['Woody', 'scent', 'woody|wood|oud|oudh|sandal|cedar|vetiver', []],
        'floral' => ['Floral', 'scent', 'floral|flower|flowery|rose|jasmine', []],
        'fruity' => ['Fruity', 'scent', 'fruity|fruit|mango|apple|peach|berry|fruitbase', ['fruitbase fragrances', 'fruitbase']],
        'spicy' => ['Spicy', 'scent', 'spicy|spice|pepper|cinnamon|cardamom|saffron', []],
        'bold' => ['Bold', 'scent', 'bold|masculine|manly|macho|powerful|aggressive|rugged', ['masculine bold']],
        'compliments' => ['Compliments', 'quality', 'compliments?|attract\w*|impress\w*|get noticed|attention|most complimented', ['most complimented']],
        'budget' => ['Budget', 'quality', 'budget|cheap|sasta|low price|under \d+|affordable|kam price|pocket|economical', []],
        'gym' => ['Gym & sport', 'occ', 'gym|sport|sports|workout|running|active|training', ['gym sport']],
        'travel' => ['Travel', 'occ', 'travel|vacation|trip|holiday|beach|goa', ['travel vacation']],
        'college' => ['College', 'occ', 'college|school|student|campus|teen|teenager', ['college fragrances', 'college']],
        'celebrity' => ['Celebrity', 'occ', 'celebrity|celeb|actor|bollywood|star', ['celebrity fragrances']],
        'daily' => ['Everyday', 'occ', 'daily|everyday|regular|rozana|casual|daily wear', ['everyday']],
        'middle-eastern' => ['Middle Eastern', 'scent', 'arabic|arab|middle eastern|attar|dubai', ['middle eastern fragrances', 'middle eastern']],
        'recommended' => ['Recommended', 'quality', 'recommend\w*', ['harsh recommendation']],
    ];
}
/** Words that do not name a product. */
function wa_prod_stop(): array
{
    return array_flip(['perfume', 'perfumes', 'fragrance', 'fragrances', 'scent', 'scents', 'attar', 'for', 'the', 'a', 'an', 'me', 'my', 'is', 'are', 'in', 'of', 'to', 'and', 'or', 'with', 'please', 'pls', 'plz', 'show', 'dikhao', 'dikha', 'bhejo', 'bhej', 'send',
        'photo', 'photos', 'pic', 'pics', 'image', 'images', 'wala', 'wali', 'waala', 'ka', 'ki', 'ke', 'ko', 'koi', 'kuch', 'ek', 'type', 'like', 'jaisa', 'jaisi', 'inspired', 'smell', 'sent', 'best', 'good', 'suggest', 'recommend', 'batao', 'bata', 'hai', 'hain', 'chahiye', 'chaiye',
        'want', 'need', 'looking', 'any', 'some', 'one', 'your', 'you', 'aap', 'hi', 'hello', 'bhai', 'sir', 'madam', 'mam', 'ji', 'do', 'de', 'dena', 'se', 'mein', 'me', 'ml', 'price', 'rate', 'cost', 'kitna', 'kya', 'konsa', 'kaun', 'which', 'what', 'tell', 'about', 'new', 'also', 'again',
        'pe', 'par', 'on', 'it', 'this', 'that', 'wo', 'woh', 'yeh', 'ye', 'can', 'u', 'ur', 'i', 'am', 'will', 'would', 'have', 'has', 'ho', 'hoga', 'hogi', 'same', 'sab', 'all', 'options', 'option', 'list', 'collection', 'range', 'variety', 'brand',
        'karo', 'kar', 'kare', 'karna', 'kijiye', 'kariye', 'dijiye', 'diye', 'mujhe', 'muje', 'mereko', 'mere', 'meri', 'mera', 'liye', 'lagane', 'lagana', 'lagaye', 'lagao', 'lagta', 'lagega', 'sakte', 'sakta', 'sakti', 'yaar', 'bro', 'kitni', 'kitna', 'kitne', 'der', 'chalta', 'chalega', 'tikta', 'tikega', 'rehta', 'rahega', 'milega', 'mil', 'sakta', 'acha', 'achha', 'accha', 'theek', 'bohot', 'bahut', 'thoda', 'wo', 'koi', 'bhi', 'toh', 'to', 'na', 'nahi', 'nhi', 'haan', 'kab', 'kaha', 'kahan', 'abhi', 'jaldi', 'pls', 'thanks', 'thank', 'ok', 'okay']);
}

function wa_prod_tables(PDO $pdo): bool
{
    static $ok = null; $GLOBALS['__wp_pdo'] = $pdo; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_prod (id INT AUTO_INCREMENT PRIMARY KEY, shopify_id BIGINT NOT NULL, handle VARCHAR(255) NOT NULL DEFAULT '', title VARCHAR(255) NOT NULL DEFAULT '', name VARCHAR(160) NOT NULL DEFAULT '',
            original_site VARCHAR(160) NOT NULL DEFAULT '', original_real VARCHAR(160) NOT NULL DEFAULT '', orig_conf TINYINT NOT NULL DEFAULT 0, gender VARCHAR(10) NOT NULL DEFAULT '', scent TEXT NULL, notes_json TEXT NULL,
            notes_top TEXT NULL, notes_mid TEXT NULL, notes_base TEXT NULL, longevity VARCHAR(40) NOT NULL DEFAULT '', seasons_json TEXT NULL, occasions_json TEXT NULL, photos_json TEXT NULL, price_text VARCHAR(60) NOT NULL DEFAULT '',
            units_sold INT NOT NULL DEFAULT 0, units_90d INT NULL, rating DECIMAL(3,2) NOT NULL DEFAULT 0, rating_n INT NOT NULL DEFAULT 0, fit_json MEDIUMTEXT NULL, kind VARCHAR(12) NOT NULL DEFAULT 'perfume', active TINYINT NOT NULL DEFAULT 1,
            url VARCHAR(400) NOT NULL DEFAULT '', enriched_at DATETIME NULL, synced_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY u_shop (shopify_id), KEY k_active (active, kind)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_prod_occ (slug VARCHAR(40) NOT NULL PRIMARY KEY, label VARCHAR(60) NOT NULL DEFAULT '', hits INT NOT NULL DEFAULT 0, source VARCHAR(8) NOT NULL DEFAULT 'auto', active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_prod_snap (day DATE NOT NULL, prod_id INT NOT NULL, units INT NOT NULL DEFAULT 0, PRIMARY KEY (day, prod_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_prod_seen (id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, prod_id INT NOT NULL, photo_idx TINYINT NOT NULL DEFAULT 0, wamid VARCHAR(80) NOT NULL DEFAULT '', at DATETIME NOT NULL, KEY k_ph (phone, at), KEY k_pp (phone, prod_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_sugg_prod (phone VARCHAR(12) NOT NULL PRIMARY KEY, in_id BIGINT NOT NULL DEFAULT 0, ids_json TEXT NULL, q VARCHAR(200) NOT NULL DEFAULT '', why_json TEXT NULL, flag VARCHAR(160) NOT NULL DEFAULT '', created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $add = ['units_30d' => 'INT NULL', 'cats_json' => 'TEXT NULL', 'tags_txt' => 'TEXT NULL', 'best_for' => "VARCHAR(60) NOT NULL DEFAULT ''", 'price_min' => 'INT NOT NULL DEFAULT 0', 'price_max' => 'INT NOT NULL DEFAULT 0', 'our_price' => 'INT NOT NULL DEFAULT 0',
            'our_ml' => "VARCHAR(20) NOT NULL DEFAULT ''", 'src_hash' => "CHAR(32) NOT NULL DEFAULT ''", 'enr_hash' => "CHAR(32) NOT NULL DEFAULT ''", 'enr_occ' => "CHAR(32) NOT NULL DEFAULT ''", 'ai_json' => 'MEDIUMTEXT NULL', 'notes_split' => 'TINYINT NOT NULL DEFAULT 0', 'search_txt' => 'TEXT NULL',
            'mf_json' => 'MEDIUMTEXT NULL', 'descr' => 'TEXT NULL', 'orig_shop' => "VARCHAR(160) NOT NULL DEFAULT ''", 'web_at' => 'DATETIME NULL', 'web_json' => 'MEDIUMTEXT NULL', 'fill_at' => 'DATETIME NULL', 'own_json' => 'MEDIUMTEXT NULL'];
        $have = []; foreach ($pdo->query("SHOW COLUMNS FROM wa_prod")->fetchAll() as $r) $have[(string)$r['Field']] = 1;
        foreach ($add as $col => $def) if (!isset($have[$col])) $pdo->exec("ALTER TABLE wa_prod ADD COLUMN $col $def");
        $have = []; foreach ($pdo->query("SHOW COLUMNS FROM wa_sugg_prod")->fetchAll() as $r) $have[(string)$r['Field']] = 1;
        if (!isset($have['lib_json'])) $pdo->exec("ALTER TABLE wa_sugg_prod ADD COLUMN lib_json TEXT NULL");
        $have = []; foreach ($pdo->query("SHOW COLUMNS FROM wa_prod_occ")->fetchAll() as $r) $have[(string)$r['Field']] = 1;
        foreach (['grp' => "VARCHAR(10) NOT NULL DEFAULT 'occ'", 'kw' => 'TEXT NULL', 'sort' => 'INT NOT NULL DEFAULT 100'] as $col => $def) if (!isset($have[$col])) $pdo->exec("ALTER TABLE wa_prod_occ ADD COLUMN $col $def");
        $ok = true;
    } catch (PDOException $e) { error_log('wa_prod_tables: ' . $e->getMessage()); $ok = false; }
    if ($ok) if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);   // set up once per upload of this file
    return $ok;
}

// ------------------------------------------------------------------------------------------------------------------ text helpers
/** Lower-case, no accents, no punctuation: "Chérie Dior" -> "cherie dior". Leet is decoded token by token when it mixes letters and symbols ("T0m F0rd" -> "tom ford"). */
function wa_prod_fold(string $s, bool $leet = true): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o', 'ō' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '&' => ' and ']);
    $s = str_replace("'", '', $s);
    if ($leet) {
        $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $i => $t) if (preg_match('/[a-z]/u', $t) && preg_match('/[@0!|3$¥]/u', $t)) $parts[$i] = strtr($t, ['@' => 'a', '0' => 'o', '!' => 'i', '|' => 'l', '3' => 'e', '$' => 's', '¥' => 'y']);
        $s = implode(' ', $parts);
    }
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
    return trim($s);
}
function wa_prod_compact(string $s): string { return str_replace(' ', '', wa_prod_fold($s)); }
/** Words of a string after folding. */
function wa_prod_words(string $s): array { $f = wa_prod_fold($s); return $f === '' ? [] : array_values(array_unique(explode(' ', $f))); }
function wa_prod_jl($v): array
{
    if (is_string($v)) { $t = trim($v); if ($t === '') return []; $d = json_decode($t, true); $v = is_array($d) ? $d : [$t]; }
    if (!is_array($v)) return [];
    $o = []; foreach ($v as $x) { if (is_array($x)) continue; $x = trim((string)$x); if ($x !== '') $o[] = $x; } return $o;
}
function wa_prod_slug(string $s): string { $f = wa_prod_fold($s, false); $f = trim(preg_replace('/[^a-z0-9]+/', '-', $f) ?? '', '-'); return substr($f, 0, 40); }
function wa_prod_money(int $n): string { return '₹' . number_format($n); }

// ------------------------------------------------------------------------------------------------------------------ normalise one Shopify product
/** A valid longevity is a number of hours ("5-8 hrs", "10+ hrs"). Anything else ("Fresh") counts as missing. Returns [text, maxHours]. */
function wa_prod_longevity(array $list): array
{
    foreach ($list as $t) {
        if (preg_match('/(\d{1,2})\s*(?:[-–—]|to)\s*(\d{1,2})\s*\+?\s*(?:hrs?|hours?|h)\b/i', (string)$t, $m)) return [(int)$m[1] . '-' . (int)$m[2] . ' hrs', (int)$m[2]];
        if (preg_match('/(\d{1,2})\s*\+\s*(?:hrs?|hours?|h)?\b/i', (string)$t, $m) && preg_match('/hrs?|hours?|h\b/i', (string)$t)) return [(int)$m[1] . '+ hrs', (int)$m[1] + 2];
        if (preg_match('/(\d{1,2})\s*(?:hrs?|hours?)\b/i', (string)$t, $m)) return [(int)$m[1] . ' hrs', (int)$m[1]];
    }
    return ['', 0];
}
function wa_prod_hours(string $lg): int { if (preg_match('/(\d+)\D+(\d+)/', $lg, $m)) return (int)$m[2]; if (preg_match('/(\d+)\+/', $lg, $m)) return (int)$m[1] + 2; return preg_match('/(\d+)/', $lg, $m) ? (int)$m[1] : 0; }
function wa_prod_seasons(array $list): array
{
    $o = [];
    foreach ($list as $t) {
        $t = mb_strtolower((string)$t); $s = '';
        if (preg_match('/winter|cold|snow/', $t)) $s = 'winter'; elseif (preg_match('/fall|autumn/', $t)) $s = 'fall'; elseif (preg_match('/spring/', $t)) $s = 'spring'; elseif (preg_match('/summer|hot/', $t)) $s = 'summer';
        if ($s !== '' && !in_array($s, $o, true)) $o[] = $s;
    }
    return $o;
}
function wa_prod_gender(string $tm, array $g): string
{
    $t = mb_strtolower(trim($tm));
    if (preg_match('/^(him|male|men|man|boys?)$/', $t)) return 'men'; if (preg_match('/^(her|female|women|woman|girls?)$/', $t)) return 'women'; if ($t === 'unisex') return 'unisex';
    $m = false; $f = false; foreach ($g as $x) { $x = mb_strtolower($x); if (preg_match('/^(male|men|him)/', $x)) $m = true; if (preg_match('/^(female|women|her)/', $x)) $f = true; }
    return $m && $f ? 'unisex' : ($m ? 'men' : ($f ? 'women' : ''));
}
function wa_prod_clean_name(string $title, string $fallback): string
{
    $t = trim($title);
    $t = preg_replace('/\s*\|\s*TM\s*Perfume\s*house.*$/iu', '', $t) ?? $t;
    $t = preg_replace('/\s*[-–—]*\s*(?:DG|LG|WH|HG|[A-Z]{2})?\s*Inspired\s+(?:perfume|fragrance|attar|body\s*mist)s?.*$/iu', '', $t) ?? $t;
    $t = preg_replace('/^[\s\-–—|]+|[\s\-–—|]+$/u', '', preg_replace('/\s+/u', ' ', $t) ?? $t) ?? $t;   // not trim() with dashes: it cuts multibyte letters in half
    return $t !== '' ? $t : trim($fallback);
}
/** One Shopify product (fixture shape) → a row. The ONE normaliser used for the live GraphQL read and for the test fixture. */
function wa_prod_normalize(array $n): array
{
    $gid = (string)($n['id'] ?? ''); $sid = preg_match('/(\d+)\s*$/', $gid, $m) ? (int)$m[1] : (int)$gid;
    $title = trim((string)($n['title'] ?? '')); $handle = trim((string)($n['handle'] ?? ''));
    $pn = trim((string)($n['perfumename'] ?? ''));
    $name = wa_prod_clean_name($title, $pn);
    $pt = mb_strtolower((string)($n['productType'] ?? '') . ' ' . $title);
    $kind = preg_match('/shower\s*gel|body\s*wash|\bgel\b/', $pt) ? 'gel' : (preg_match('/candle/', $pt) ? 'candle' : (preg_match('/perfume|attar|fragrance|inspired|body\s*mist|\bmist\b|parfum|deo/', $pt) || trim((string)($n['inspiredby'] ?? '')) !== '' ? 'perfume' : 'other'));
    $notes = wa_prod_jl($n['notes'] ?? ''); if (!$notes) $notes = wa_prod_jl($n['perfume_notes'] ?? []);
    $seen = []; $nn = []; foreach ($notes as $x) { $k = mb_strtolower($x); if (!isset($seen[$k])) { $seen[$k] = 1; $nn[] = mb_substr($x, 0, 40); } } $notes = array_slice($nn, 0, 16);
    [$lg] = wa_prod_longevity(wa_prod_jl($n['longevity'] ?? ''));
    $seasons = wa_prod_seasons(array_merge(wa_prod_jl($n['season'] ?? []), [])); $order = ['winter', 'spring', 'summer', 'fall']; usort($seasons, fn($a, $b) => array_search($a, $order) <=> array_search($b, $order));
    $photos = []; foreach (array_merge([(string)($n['featured'] ?? '')], wa_prod_jl($n['photos'] ?? [])) as $u) { $u = trim((string)$u); if ($u !== '' && preg_match('#^https?://#i', $u) && !in_array($u, $photos, true)) $photos[] = $u; } $photos = array_slice($photos, 0, 4);
    $cats = []; foreach (wa_prod_jl($n['categories'] ?? '') as $c) { $c = trim(preg_replace('/\s+/u', ' ', $c) ?? $c); if ($c !== '' && !in_array($c, $cats, true)) $cats[] = $c; }
    $ourPrice = preg_match('/(\d[\d,]*)/', (string)($n['our_price'] ?? ''), $m) ? (int)str_replace(',', '', $m[1]) : 0;
    $ml = trim((string)($n['our_ml'] ?? '')); $ml = preg_match('/^\d+\s*ml$/i', $ml) ? strtolower(str_replace(' ', '', $ml)) : (preg_match('/^(\d+)$/', $ml, $mm) ? $mm[1] . 'ml' : '');
    $pmin = (int)round((float)($n['price_min'] ?? 0)); $pmax = (int)round((float)($n['price_max'] ?? 0));
    $price = $ourPrice > 0 ? wa_prod_money($ourPrice) . ($ml !== '' ? ' for ' . $ml : '') : ($pmin > 0 ? 'Starts at ' . wa_prod_money($pmin) : '');
    $rv = $n['rating'] ?? ''; if (is_string($rv)) { $d = json_decode($rv, true); $rv = is_array($d) ? ($d['value'] ?? 0) : $rv; } elseif (is_array($rv)) $rv = $rv['value'] ?? 0;
    $rating = max(0, min(5, round((float)$rv, 2)));
    $tags = wa_prod_jl($n['tags'] ?? []); $tagsTxt = mb_substr(implode(', ', array_slice($tags, 0, 60)), 0, 3000);
    $handleUrl = $handle !== '' ? 'https://tmperfumehouse.com/products/' . $handle : '';
    $row = ['shopify_id' => $sid, 'handle' => mb_substr($handle, 0, 255), 'title' => mb_substr($title, 0, 255), 'name' => mb_substr($name, 0, 160), 'original_site' => mb_substr(trim((string)($n['inspiredby'] ?? '')), 0, 160),
        'gender' => wa_prod_gender((string)($n['tmgender'] ?? ''), wa_prod_jl($n['gender'] ?? '')), 'scent' => mb_substr(implode('; ', wa_prod_jl($n['scent_profile'] ?? '')), 0, 1500), 'notes' => $notes, 'longevity' => $lg, 'seasons' => $seasons, 'photos' => $photos,
        'price_text' => $price, 'price_min' => $pmin, 'price_max' => $pmax, 'our_price' => $ourPrice, 'our_ml' => $ml, 'units_sold' => max(0, (int)preg_replace('/\D/', '', (string)($n['units_sold'] ?? '0'))), 'rating' => $rating,
        'rating_n' => max(0, (int)preg_replace('/\D/', '', (string)($n['rating_count'] ?? '0'))), 'kind' => $kind, 'active' => strtoupper((string)($n['status'] ?? 'ACTIVE')) === 'ACTIVE' ? 1 : 0,
        'url' => mb_substr(trim((string)($n['url'] ?? '')) !== '' ? trim((string)$n['url']) : $handleUrl, 0, 400), 'cats' => $cats, 'tags_txt' => $tagsTxt, 'best_for' => mb_substr(trim((string)($n['best_for'] ?? '')), 0, 60)];
    $mf = wa_prod_mf_clean((array)($n['mf'] ?? [])); $row['mf'] = $mf; $row['orig_shop'] = wa_prod_orig_from_mf($mf);
    $row['descr'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($n['descr'] ?? '')) ?? ''), 0, 500);
    $row['src_hash'] = md5(json_encode([$row['name'], $row['original_site'], $row['gender'], $row['scent'], $row['notes'], $row['seasons'], $row['cats'], $row['kind'], $row['tags_txt']], JSON_UNESCAPED_UNICODE));
    return $row;
}

// ------------------------------------------------------------------------------------------------------------------ Shopify read (GraphQL, paginated). Test seams: wa_prod_shop_token(), wa_http(), wa_prod_ai().
if (!function_exists('wa_prod_shop_token')) {
    /** [token, error, domain] — the existing HRMS store login (client credentials). */
    function wa_prod_shop_token(): array
    {
        if (!function_exists('shopify_stores') || !function_exists('shopify_get_token')) return ['', 'The Shopify login is not available in this build.', ''];
        $st = shopify_stores(); $key = (string)array_key_first($st); if ($key === '') return ['', 'No Shopify store is set up.', ''];
        $t = shopify_get_token($key, $st[$key]); if (is_array($t)) return ['', (string)($t['error'] ?? 'Could not log in to Shopify.'), ''];
        return [(string)$t, '', (string)$st[$key]['domain']];
    }
}
if (!function_exists('wa_prod_ai')) {
    /** One AI call that must answer JSON. [ok, value, why]. Tests replace this. */
    function wa_prod_ai(string $sys, array $parts, array $schema, int $maxOut = 6000): array
    {
        if (!function_exists('convo_ai')) return [false, null, 'The AI is not available.'];
        $r = convo_ai($sys, $parts, $schema, false, $maxOut, 'claude'); return [(bool)($r[0] ?? false), $r[1] ?? null, (string)($r[2] ?? '')];
    }
}
function wa_prod_gql_query(): string
{
    $mf = '';
    foreach (wa_prod_mf() as $alias => $nk) {
        $mf .= ' m_' . $alias . ': metafield(namespace: "' . $nk[0] . '", key: "' . $nk[1] . '") { value' . ($alias === 'season' ? ' references(first: 8) { nodes { ... on Metaobject { displayName fields { key value } } } }' : '') . ' }';
    }
    return 'query($cursor: String) { products(first: 12, after: $cursor, query: "status:active") { pageInfo { hasNextPage endCursor } nodes { id handle title productType vendor status tags onlineStoreUrl featuredImage { url } images(first: 4) { nodes { url } }'
        . ' description(truncateAt: 500) allmf: metafields(first: 30) { nodes { namespace key value } }'   // EVERY metafield the product has (popup names, approvals, longevity…), not only the ones named above
        . ' priceRangeV2 { minVariantPrice { amount } maxVariantPrice { amount } }' . $mf . ' } } }';
}
/** Every metafield as "namespace.key" => short readable text (JSON lists joined, ids / links / huge values left out). */
function wa_prod_mf_clean(array $mf): array
{
    $o = [];
    foreach ($mf as $k => $v) {
        $k = mb_substr(trim((string)$k), 0, 80); $v = trim((string)$v); if ($k === '' || $v === '') continue;
        $d = json_decode($v, true);
        if (is_array($d)) { if (isset($d['value']) && !is_array($d['value'])) $v = (string)$d['value']; else { $fl = []; array_walk_recursive($d, function ($x) use (&$fl) { if (is_scalar($x) && trim((string)$x) !== '') $fl[] = trim((string)$x); }); $v = implode(', ', $fl); } }
        elseif (is_bool($d)) $v = $d ? 'yes' : 'no'; elseif (is_scalar($d) && $d !== null) $v = (string)$d;
        $v = trim(strip_tags($v)); if ($v === '' || preg_match('#^(gid://|https?://)#i', $v)) continue;
        $o[$k] = mb_substr(preg_replace('/\s+/u', ' ', $v) ?? $v, 0, 300);
    }
    return $o;
}
/** The real original perfume name when Shopify has it in a metafield (e.g. the popup's original name). The key can be fixed with the setting wa_prod_orig_mf ("custom.popup_original_name"); otherwise it is found by its name. */
function wa_prod_orig_from_mf(array $mf): string
{
    $want = ''; try { $pdo = $GLOBALS['__wp_pdo'] ?? null; if ($pdo instanceof PDO) $want = trim(wa_prod_cfg($pdo, 'wa_prod_orig_mf', '')); } catch (Throwable $e) { }
    $ok = function ($v) { $v = trim((string)$v); return $v !== '' && mb_strlen($v) >= 3 && mb_strlen($v) <= 160 && preg_match('/\p{L}{2}/u', $v) && !preg_match('/^\d+([.,]\d+)?\s*(ml|rs|₹|inr)?$/iu', $v) ? $v : ''; };
    if ($want !== '') return isset($mf[$want]) ? $ok($mf[$want]) : '';
    $best = ''; $bestScore = 0;
    foreach ($mf as $k => $v) {
        $key = mb_strtolower((string)(strpos($k, '.') !== false ? substr($k, strpos($k, '.') + 1) : $k));
        if (in_array($key, ['inspiredby', 'inspired_by', 'perfumename', 'perfume_name'], true) || preg_match('/price|\bml\b|_ml|ml_|size|image|photo|url|link|rating|count|sold|note|gender|season|categor|longevity|qty|stock/', $key)) continue;
        $sc = preg_match('/original|recreat|clone|dupe|real_?name|real_?perfume|brand_?name/', $key) ? (preg_match('/name|perfume/', $key) ? 3 : 2) : (preg_match('/popup/', $key) && preg_match('/name|title|perfume/', $key) ? 2 : 0);
        if ($sc > $bestScore && ($val = $ok($v)) !== '') { $best = $val; $bestScore = $sc; }
    }
    return $best;
}
/** Looks up the REAL original perfume on the internet (Claude + web search) for perfumes where neither Shopify nor Claude's own knowledge is sure. A few per run; each product is asked at most once a fortnight. */
function wa_prod_web_originals(PDO $pdo, int $limit = 10, array $ids = []): array
{
    $res = ['ok' => false, 'found' => 0, 'asked' => 0, 'left' => 0, 'error' => ''];
    if (!wa_prod_tables($pdo)) { $res['error'] = 'Product storage is not available.'; return $res; }
    if (!function_exists('brain_claude_call')) { $f = __DIR__ . '/brain.php'; if (is_file($f)) require_once $f; }
    if (!function_exists('brain_claude_call')) { $res['error'] = 'Claude is not available.'; return $res; }
    $where = "active = 1 AND kind = 'perfume' AND orig_conf < 50 AND (web_at IS NULL OR web_at < ?)"; $args = [date('Y-m-d H:i:s', time() - 14 * 86400)];
    if ($ids) { $ids = array_values(array_unique(array_map('intval', $ids))); $where .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $args = array_merge($args, $ids); }
    $q = $pdo->prepare("SELECT id, title, name, original_site, gender, notes_json, descr FROM wa_prod WHERE $where ORDER BY units_sold DESC, id LIMIT " . max(1, min(15, $limit))); $q->execute($args); $rows = $q->fetchAll();
    $c = $pdo->prepare("SELECT COUNT(*) FROM wa_prod WHERE active = 1 AND kind = 'perfume' AND orig_conf < 50"); $c->execute(); $res['left'] = (int)$c->fetchColumn();
    if (!$rows) { $res['ok'] = true; return $res; }
    $items = []; foreach ($rows as $p) $items[] = ['id' => (int)$p['id'], 'website_title' => $p['title'], 'our_name' => $p['name'], 'website_spelling_of_original' => $p['original_site'], 'gender' => $p['gender'], 'notes' => array_slice((array)json_decode((string)$p['notes_json'], true), 0, 10), 'description' => mb_substr((string)$p['descr'], 0, 240)];
    $sys = "TM Perfume House (India) sells its own inspired-by versions of famous perfumes; the website disguises the original's name on purpose (leet-speak, abbreviations, e.g. 'Jpg Layman Elixir' = Jean Paul Gaultier Le Male Elixir, 'D COOL WTR' = Davidoff Cool Water). "
        . "For every product find the REAL original perfume: brand + exact perfume name (flanker included: Elixir, Intense, Parfum…). Use web search when you are not sure; match the notes. "
        . "Reply with JSON only, no other text: {\"items\":[{\"id\":1,\"original\":\"Brand Perfume Name\",\"confidence\":0-100}]}. Unsure → original \"\" and confidence 0.";
    [$ok, $t, $why] = brain_claude_call($sys, json_encode($items, JSON_UNESCAPED_UNICODE), 2500, ['web' => min(10, count($items) + 2), 'timeout' => 150, 'purpose' => 'products:web_original']);
    $pdo->prepare("UPDATE wa_prod SET web_at = ? WHERE id IN (" . implode(',', array_map('intval', array_column($rows, 'id'))) . ")")->execute([date('Y-m-d H:i:s')]);
    $res['asked'] = count($rows);
    if (!$ok) { $res['error'] = (string)($why ?: 'Claude did not answer.'); return $res; }
    $j = null; $p0 = strrpos((string)$t, '{"items"'); if ($p0 === false && preg_match_all('/\{\s*"items"/', (string)$t, $mm, PREG_OFFSET_CAPTURE)) $p0 = end($mm[0])[1];
    if ($p0 !== false && preg_match('/\{.*\}/s', substr((string)$t, (int)$p0), $m2)) $j = json_decode($m2[0], true);
    if (!is_array($j) || !is_array($j['items'] ?? null)) { $res['error'] = 'Claude answered in a form we could not read.'; return $res; }
    $byId = array_flip(array_map('intval', array_column($rows, 'id'))); $u = $pdo->prepare("UPDATE wa_prod SET original_real = ?, orig_conf = 95 WHERE id = ? AND orig_conf < 95");
    foreach ($j['items'] as $it) {
        if (!is_array($it) || !isset($byId[(int)($it['id'] ?? 0)])) continue; $o = trim(preg_replace('/\s+/u', ' ', (string)($it['original'] ?? '')) ?? '');
        if ($o !== '' && (int)($it['confidence'] ?? 0) >= 60 && mb_strlen($o) <= 160) { $u->execute([$o, (int)$it['id']]); $res['found']++; }
    }
    if ($res['found']) wa_prod_retag($pdo);
    $c->execute(); $res['left'] = (int)$c->fetchColumn(); $res['ok'] = true; return $res;
}
/** Fills what Shopify leaves empty (long lasting, notes, gender) from the internet, using the REAL original name (Shopify's own field first). Kept apart in web_json —
 *  the Shopify values always win, these only fill the gaps. A few per run; each product at most once a month. */
function wa_prod_web_fill(PDO $pdo, int $limit = 10): array
{
    $res = ['ok' => false, 'filled' => 0, 'asked' => 0, 'left' => 0, 'error' => ''];
    if (!wa_prod_tables($pdo)) { $res['error'] = 'Product storage is not available.'; return $res; }
    if (!function_exists('brain_claude_call')) { $f = __DIR__ . '/brain.php'; if (is_file($f)) require_once $f; }
    if (!function_exists('brain_claude_call')) { $res['error'] = 'Claude is not available.'; return $res; }
    $need = "active = 1 AND kind = 'perfume' AND original_real <> '' AND orig_conf >= 50 AND (longevity = '' OR notes_json IS NULL OR notes_json IN ('', '[]') OR gender = '')";
    $c = $pdo->query("SELECT COUNT(*) FROM wa_prod WHERE $need AND (fill_at IS NULL OR fill_at < '" . date('Y-m-d H:i:s', time() - 30 * 86400) . "')"); $res['left'] = (int)$c->fetchColumn();
    $rows = $pdo->query("SELECT id, title, original_real, longevity, notes_json, gender FROM wa_prod WHERE $need AND (fill_at IS NULL OR fill_at < '" . date('Y-m-d H:i:s', time() - 30 * 86400) . "') ORDER BY units_sold DESC, id LIMIT " . max(1, min(12, $limit)))->fetchAll();
    if (!$rows) { $res['ok'] = true; return $res; }
    $items = []; foreach ($rows as $p) $items[] = ['id' => (int)$p['id'], 'original_perfume' => $p['original_real'], 'missing' => array_values(array_filter([$p['longevity'] === '' ? 'longevity' : '', in_array((string)$p['notes_json'], ['', '[]'], true) ? 'notes' : '', $p['gender'] === '' ? 'gender' : '']))];
    $sys = "For each famous original perfume, find the missing facts with web search (Fragrantica, Parfumo, the brand's site): longevity as a short range like '6-8 hrs' or '8-10 hrs' or '10+ hrs', "
        . "the main notes (top, middle, base — up to 10 short note names), and gender (men / women / unisex). Only fill what is listed as missing. Reply with JSON only, no other text: "
        . "{\"items\":[{\"id\":1,\"longevity\":\"8-10 hrs\",\"notes\":[\"Lavender\"],\"gender\":\"men\"}]}. Unsure → leave that field empty.";
    [$ok, $t, $why] = brain_claude_call($sys, json_encode($items, JSON_UNESCAPED_UNICODE), 3000, ['web' => min(12, count($items) + 2), 'timeout' => 150, 'purpose' => 'products:web_fill']);
    $pdo->query("UPDATE wa_prod SET fill_at = '" . date('Y-m-d H:i:s') . "' WHERE id IN (" . implode(',', array_map('intval', array_column($rows, 'id'))) . ")");
    $res['asked'] = count($rows); if (!$ok) { $res['error'] = (string)($why ?: 'Claude did not answer.'); return $res; }
    $j = null; $p0 = strrpos((string)$t, '{"items"'); if ($p0 === false && preg_match_all('/\{\s*"items"/', (string)$t, $mm, PREG_OFFSET_CAPTURE)) $p0 = end($mm[0])[1];
    if ($p0 !== false && preg_match('/\{.*\}/s', substr((string)$t, (int)$p0), $m2)) $j = json_decode($m2[0], true);
    if (!is_array($j) || !is_array($j['items'] ?? null)) { $res['error'] = 'Claude answered in a form we could not read.'; return $res; }
    $byId = array_flip(array_map('intval', array_column($rows, 'id'))); $u = $pdo->prepare("UPDATE wa_prod SET web_json = ? WHERE id = ?");
    foreach ($j['items'] as $it) {
        if (!is_array($it) || !isset($byId[(int)($it['id'] ?? 0)])) continue; $w = [];
        $lg = trim((string)($it['longevity'] ?? '')); if ($lg !== '' && mb_strlen($lg) <= 20 && preg_match('/\d/', $lg)) $w['longevity'] = $lg;
        $nt = array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), (array)($it['notes'] ?? []))), 0, 12)); if ($nt) $w['notes'] = $nt;
        $g = strtolower(trim((string)($it['gender'] ?? ''))); if (in_array($g, ['men', 'women', 'unisex'], true)) $w['gender'] = $g;
        if ($w) { $u->execute([json_encode($w + ['from' => 'web', 'at' => date('Y-m-d')], JSON_UNESCAPED_UNICODE), (int)$it['id']]); $res['filled']++; }
    }
    $res['left'] = max(0, $res['left'] - count($rows)); $res['ok'] = true; return $res;
}
/** Where the real original names come from — for the owner to check that Shopify's own field is read. */
function wa_prod_orig_stats(PDO $pdo): array
{
    $o = ['perfumes' => 0, 'shopify' => 0, 'web' => 0, 'claude' => 0, 'unknown' => 0, 'keys' => [], 'filled' => 0, 'cands' => [], 'chosen' => ''];
    try { $o['chosen'] = trim(wa_prod_cfg($pdo, 'wa_prod_orig_mf', '')); } catch (Throwable $e) { } $cand = [];
    if (!wa_prod_tables($pdo)) return $o;
    foreach ($pdo->query("SELECT orig_conf, original_real, mf_json, web_json FROM wa_prod WHERE active = 1 AND kind = 'perfume'")->fetchAll() as $r) {
        $o['perfumes']++; $c = (int)$r['orig_conf']; $has = trim((string)$r['original_real']) !== '';
        if ($has && $c >= 100) { $o['shopify']++; foreach ((array)json_decode((string)$r['mf_json'], true) as $k => $v) if (trim((string)$v) === trim((string)$r['original_real'])) { $o['keys'][$k] = ($o['keys'][$k] ?? 0) + 1; break; } }
        elseif ($has && $c >= 95) $o['web']++; elseif ($has && $c >= 50) $o['claude']++; else $o['unknown']++;
        if (trim((string)$r['web_json']) !== '') $o['filled']++;
        foreach ((array)json_decode((string)$r['mf_json'], true) as $k => $v) { $v = trim((string)$v); if ($v === '' || mb_strlen($v) > 80 || !preg_match('/\p{L}{3}/u', $v)) continue; if (!isset($cand[$k])) $cand[$k] = ['key' => $k, 'n' => 0, 'sample' => mb_substr($v, 0, 60)]; $cand[$k]['n']++; }
    }
    usort($cand, fn($a, $b) => $b['n'] <=> $a['n']); $o['cands'] = array_slice(array_values($cand), 0, 25);   // the text fields the perfumes have — the owner can pick the one that holds the original name
    arsort($o['keys']); return $o;
}
/** The owner picked which Shopify field holds the real original name ('' = find it by its name): saved, and applied at once from the fields already read. */
function wa_prod_orig_key_set(PDO $pdo, string $key): int
{
    if (!wa_prod_tables($pdo)) return 0; setting_set($pdo, 'wa_prod_orig_mf', mb_substr(trim($key), 0, 80));
    $u = $pdo->prepare("UPDATE wa_prod SET orig_shop = ? WHERE id = ?"); $n = 0;
    foreach ($pdo->query("SELECT id, mf_json FROM wa_prod")->fetchAll() as $r) { $v = wa_prod_orig_from_mf((array)json_decode((string)$r['mf_json'], true)); $u->execute([mb_substr($v, 0, 160), $r['id']]); if ($v !== '') $n++; }
    $pdo->exec("UPDATE wa_prod SET original_real = orig_shop, orig_conf = 100 WHERE orig_shop <> ''");
    $pdo->exec("UPDATE wa_prod SET original_real = '', orig_conf = 0 WHERE orig_shop = '' AND orig_conf = 100");
    wa_prod_retag($pdo); return $n;
}
/** Saves the owner's correction of one product (longevity / notes / gender / original); an empty value removes that correction. Returns the corrections now kept. */
function wa_prod_own_set(PDO $pdo, int $id, array $fix): array
{
    if (!wa_prod_tables($pdo)) return []; $q = $pdo->prepare("SELECT own_json FROM wa_prod WHERE id = ?"); $q->execute([$id]); $cur = json_decode((string)$q->fetchColumn(), true); $cur = is_array($cur) ? $cur : [];
    foreach (['longevity', 'gender', 'original'] as $k) if (array_key_exists($k, $fix)) { $v = mb_substr(trim((string)$fix[$k]), 0, $k === 'original' ? 160 : 30); if ($v === '') unset($cur[$k]); else $cur[$k] = $v; }
    if (array_key_exists('notes', $fix)) { $n = array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 40), (array)$fix['notes'])), 0, 12)); if ($n) $cur['notes'] = $n; else unset($cur['notes']); }
    $pdo->prepare("UPDATE wa_prod SET own_json = ? WHERE id = ?")->execute([$cur ? json_encode($cur + ['at' => date('Y-m-d')], JSON_UNESCAPED_UNICODE) : null, $id]);
    return $cur;
}
/** GraphQL node → the flat shape used by the fixture. */
function wa_prod_node_to_flat(array $n): array
{
    $o = ['id' => (string)($n['id'] ?? ''), 'handle' => (string)($n['handle'] ?? ''), 'title' => (string)($n['title'] ?? ''), 'productType' => (string)($n['productType'] ?? ''), 'vendor' => (string)($n['vendor'] ?? ''), 'status' => (string)($n['status'] ?? 'ACTIVE'),
        'tags' => $n['tags'] ?? [], 'url' => (string)($n['onlineStoreUrl'] ?? ''), 'featured' => (string)($n['featuredImage']['url'] ?? ''), 'photos' => []];
    foreach (($n['images']['nodes'] ?? []) as $im) if (!empty($im['url'])) $o['photos'][] = (string)$im['url'];
    $o['price_min'] = (string)($n['priceRangeV2']['minVariantPrice']['amount'] ?? '0'); $o['price_max'] = (string)($n['priceRangeV2']['maxVariantPrice']['amount'] ?? '0');
    $o['descr'] = (string)($n['description'] ?? ''); $o['mf'] = [];
    foreach (($n['allmf']['nodes'] ?? []) as $m) if (is_array($m) && ($m['key'] ?? '') !== '') $o['mf'][(string)($m['namespace'] ?? '') . '.' . (string)$m['key']] = (string)($m['value'] ?? '');
    foreach (array_keys(wa_prod_mf()) as $alias) {
        $m = $n['m_' . $alias] ?? null; if (!is_array($m)) { $o[$alias] = ($alias === 'season' || $alias === 'perfume_notes') ? [] : ''; continue; }
        if ($alias === 'season') {
            $names = [];
            foreach (($m['references']['nodes'] ?? []) as $r) {
                $dn = trim((string)($r['displayName'] ?? ''));
                if ($dn === '') foreach (($r['fields'] ?? []) as $f) if (in_array($f['key'] ?? '', ['label', 'name'], true)) $dn = (string)$f['value'];
                if ($dn !== '') $names[] = $dn;
            }
            $o[$alias] = $names ?: wa_prod_jl((string)($m['value'] ?? '')); continue;
        }
        $o[$alias] = $alias === 'perfume_notes' ? wa_prod_jl((string)($m['value'] ?? '')) : (string)($m['value'] ?? '');
    }
    return $o;
}
/** One page. Returns ['products'=>[flat…], 'hasNext'=>bool, 'cursor'=>?string, 'error'=>string]. */
function wa_prod_fetch_shopify(?string $cursor): array
{
    [$tok, $err, $domain] = wa_prod_shop_token();
    if ($tok === '') return ['products' => [], 'hasNext' => false, 'cursor' => null, 'error' => $err ?: 'Could not log in to Shopify.'];
    $need = 'Shopify did not allow reading products. In Shopify go to Settings > Apps > Develop apps, open the HRMS app, add the access scope "read_products" (Products), save and re-install the app.';
    for ($try = 0; $try < 3; $try++) {
        [$code, $d] = wa_http('POST', 'https://' . $domain . '/admin/api/2024-10/graphql.json', ['Content-Type: application/json', 'X-Shopify-Access-Token: ' . $tok], json_encode(['query' => wa_prod_gql_query(), 'variables' => ['cursor' => $cursor]]));
        if ($code === 401 || $code === 403) return ['products' => [], 'hasNext' => false, 'cursor' => null, 'error' => $need];
        $errs = is_array($d) ? ($d['errors'] ?? null) : null; $es = is_array($errs) ? json_encode($errs) : (string)$errs;
        if ($errs && preg_match('/ACCESS_DENIED|access denied|scope/i', $es)) return ['products' => [], 'hasNext' => false, 'cursor' => null, 'error' => $need];
        if (($code === 429 || ($errs && preg_match('/THROTTLED/i', $es))) && $try < 2) { sleep(2); continue; }
        if ($code < 200 || $code >= 300 || !is_array($d) || empty($d['data']['products'])) return ['products' => [], 'hasNext' => false, 'cursor' => null, 'error' => 'Shopify did not answer properly (HTTP ' . $code . ').' . ($errs ? ' ' . mb_substr($es, 0, 160) : '')];
        $p = $d['data']['products']; $out = []; foreach (($p['nodes'] ?? []) as $n) if (is_array($n)) $out[] = wa_prod_node_to_flat($n);
        return ['products' => $out, 'hasNext' => !empty($p['pageInfo']['hasNextPage']), 'cursor' => $p['pageInfo']['endCursor'] ?? null, 'error' => ''];
    }
    return ['products' => [], 'hasNext' => false, 'cursor' => null, 'error' => 'Shopify is busy. Try again in a minute.'];
}

// ------------------------------------------------------------------------------------------------------------------ request types (occasions)
function wa_prod_cat_slugs(string $cat): array
{
    $f = wa_prod_fold($cat, false); if ($f === '') return [];
    $o = [];
    if (preg_match('/best sellers?\b|best seller/', $f)) $o[] = 'best-sellers';
    if (preg_match('/daily office|\boffice\b/', $f)) $o[] = 'office';
    if (preg_match('/date night|evening/', $f)) $o[] = 'date-night';
    if (preg_match('/\bfresh/', $f)) $o[] = 'fresh';
    if (preg_match('/\bsummer\b/', $f)) $o[] = 'summer';
    if (preg_match('/\bwinter\b/', $f)) $o[] = 'winter';
    if (preg_match('/party|night ?out/', $f)) $o[] = 'party';
    if (preg_match('/long lasting|longlasting/', $f)) $o[] = 'long-lasting';
    if (preg_match('/masculine|\bbold\b/', $f)) $o[] = 'bold';
    if (preg_match('/complimented/', $f)) $o[] = 'compliments';
    if (preg_match('/\bgym\b|\bsport/', $f)) $o[] = 'gym';
    if (preg_match('/travel|vacation/', $f)) $o[] = 'travel';
    if (preg_match('/celebrity/', $f)) $o[] = 'celebrity';
    if (preg_match('/everyday/', $f)) $o[] = 'daily';
    if (preg_match('/college/', $f)) $o[] = 'college';
    if (preg_match('/fruit/', $f)) $o[] = 'fruity';
    if (preg_match('/middle eastern/', $f)) $o[] = 'middle-eastern';
    if (preg_match('/recommend/', $f)) $o[] = 'recommended';
    if (!$o) { $own = preg_replace('/\b(men|women|him|her|for|fragrances?|perfumes?|and)\b/', ' ', $f) ?? $f; $s = wa_prod_slug(trim($own)); if ($s !== '') $o[] = $s; }
    return array_values(array_unique($o));
}
/** Makes sure the standard request types exist and adds one for every unknown site category. Never changes hits / active of existing ones. */
function wa_prod_occ_seed(PDO $pdo): int
{
    $n = 0; $i = 0; $ins = $pdo->prepare("INSERT IGNORE INTO wa_prod_occ (slug, label, hits, source, active, grp, kw, sort) VALUES (?, ?, 0, 'site', 1, ?, ?, ?)");
    $fix = $pdo->prepare("UPDATE wa_prod_occ SET grp = ?, kw = ?, sort = ? WHERE slug = ?");   // the standard request types are owned by the code: keep their words up to date (hits / on-off are never touched)
    foreach (wa_prod_occ_defaults() as $slug => $d) { $i++; $ins->execute([$slug, $d[0], $d[1], $d[2], $i * 10]); $n += $ins->rowCount(); if (!$ins->rowCount()) $fix->execute([$d[1], $d[2], $i * 10, $slug]); }
    $have = []; foreach ($pdo->query("SELECT slug FROM wa_prod_occ")->fetchAll() as $r) $have[(string)$r['slug']] = 1;
    $labels = [];
    foreach ($pdo->query("SELECT cats_json FROM wa_prod WHERE active = 1")->fetchAll() as $r) foreach ((array)json_decode((string)$r['cats_json'], true) as $c) foreach (wa_prod_cat_slugs((string)$c) as $s) if (!isset($have[$s]) && !isset($labels[$s])) {
        $lab = trim(preg_replace('/\s*[–-]\s*(men|women)\s*$/iu', '', trim((string)$c)) ?? (string)$c); $labels[$s] = mb_substr($lab, 0, 60);
    }
    foreach ($labels as $s => $lab) { $ins->execute([$s, $lab, 'occ', preg_quote(wa_prod_fold($lab, false), '/'), 500]); $n += $ins->rowCount(); }
    return $n;
}
function wa_prod_occ_rows(PDO $pdo, bool $activeOnly = false): array
{
    $q = $pdo->query("SELECT * FROM wa_prod_occ" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY sort, slug"); return $q ? $q->fetchAll() : [];
}
function wa_prod_occ_hash(PDO $pdo): string { $s = []; foreach ($pdo->query("SELECT slug FROM wa_prod_occ ORDER BY slug")->fetchAll() as $r) $s[] = $r['slug']; return md5(implode(',', $s)); }
/** A request type's keyword regex (alternatives, no delimiters). Falls back to its label. Invalid patterns never break a search. */
function wa_prod_occ_re(array $o): string
{
    $kw = trim((string)($o['kw'] ?? '')); if ($kw === '') $kw = preg_quote(wa_prod_fold((string)$o['label'], false), '/');
    $re = '/(?<![a-z0-9])(?:' . $kw . ')(?![a-z0-9])/iu';
    if (@preg_match($re, '') === false) $re = '/(?<![a-z0-9])(?:' . preg_quote(wa_prod_fold((string)$o['label'], false), '/') . ')(?![a-z0-9])/iu';
    return $re;
}

// ------------------------------------------------------------------------------------------------------------------ rule-based fit (no AI needed)
function wa_prod_fam_of(array $notes, string $extra): array
{
    $fam = []; $fold = array_map(fn($x) => mb_strtolower($x), $notes); $ex = mb_strtolower($extra);
    foreach (wa_prod_families() as $f => $re) {
        $cnt = 0; foreach ($fold as $x) if (preg_match('/' . $re . '/iu', $x)) $cnt++;
        if ($cnt === 0 && $ex !== '' && preg_match('/' . $re . '/iu', $ex)) $cnt = 1;
        if ($cnt > 0) $fam[$f] = $cnt;
    }
    return $fam;
}
function wa_prod_split_notes(array $notes): array
{
    $n = count($notes); if ($n === 0) return ['', '', ''];
    if ($n === 1) return [$notes[0], '', ''];
    if ($n === 2) return [$notes[0], '', $notes[1]];
    $t = max(1, (int)round($n * 0.34)); $b = max(1, (int)round($n * 0.33)); while ($t + $b >= $n) { if ($b > 1) $b--; elseif ($t > 1) $t--; else break; }
    return [implode(', ', array_slice($notes, 0, $t)), implode(', ', array_slice($notes, $t, $n - $t - $b)), implode(', ', array_slice($notes, $n - $b))];
}
/** Score 0-100 per request type (not the 'who' group). $rank = place by sales 0..1. Deterministic. */
function wa_prod_rule_fit(array $p, array $occ, float $rank): array
{
    $notes = (array)(json_decode((string)$p['notes_json'], true) ?: []); $seasons = (array)(json_decode((string)$p['seasons_json'], true) ?: []); $cats = (array)(json_decode((string)$p['cats_json'], true) ?: []);
    $fam = wa_prod_fam_of($notes, (string)$p['scent']); $has = fn($k) => isset($fam[$k]); $hours = wa_prod_hours((string)$p['longevity']); $nS = count($seasons);
    $catSlugs = []; foreach ($cats as $c) foreach (wa_prod_cat_slugs((string)$c) as $s) $catSlugs[$s] = 1;
    $warm = (int)$has('amber') + (int)$has('spicy') + (int)$has('oud') + (int)$has('leather') + (int)$has('sweet') + (int)$has('woody');
    $cool = (int)$has('citrus') + (int)$has('aquatic') + (int)$has('fresh');
    $rating = (float)$p['rating']; $price = (int)$p['our_price']; $m = [];
    foreach ($occ as $o) {
        $s = (string)$o['slug']; $g = (string)($o['grp'] ?? 'occ'); if ($g === 'who') continue;
        switch ($s) {
            case 'winter': $v = in_array('winter', $seasons, true) ? ($nS <= 2 ? 85 : 70) : ($seasons ? 20 : ($warm >= 2 ? 65 : 30)); $v += $warm >= 2 ? 10 : 0; $v -= $cool >= 2 ? 10 : 0; break;
            case 'summer': $v = in_array('summer', $seasons, true) ? ($nS <= 2 ? 85 : 70) : ($seasons ? 20 : ($cool >= 1 ? 65 : 30)); $v += $cool >= 2 ? 10 : 0; $v -= $warm >= 2 ? 15 : 0; break;
            case 'spring': $v = in_array('spring', $seasons, true) ? ($nS <= 2 ? 80 : 65) : ($seasons ? 20 : 35); $v += ($has('floral') || $has('fresh')) ? 5 : 0; break;
            case 'fall': $v = in_array('fall', $seasons, true) ? ($nS <= 2 ? 80 : 65) : ($seasons ? 20 : 35); $v += $warm >= 1 ? 5 : 0; break;
            case 'office': $v = 35 + ($cool ? 20 : 0) + ($has('woody') ? 15 : 0) - (($has('sweet') || $has('oud')) ? 20 : 0) + (($hours >= 5 && $hours <= 8) ? 10 : 0); break;
            case 'date-night': $v = 30 + ($has('sweet') ? 15 : 0) + ($has('amber') ? 15 : 0) + ($has('spicy') ? 15 : 0) + ($has('floral') ? 10 : 0) + ($has('leather') ? 10 : 0) + ($has('oud') ? 5 : 0) - (($cool && !$warm) ? 15 : 0); break;
            case 'party': $v = 30 + ($has('sweet') ? 15 : 0) + ($has('spicy') ? 15 : 0) + ($has('amber') ? 10 : 0) + ($has('fruity') ? 10 : 0) + ($hours >= 8 ? 10 : 0) + (($has('oud') || $has('leather')) ? 10 : 0); break;
            case 'wedding': $v = 30 + ($has('oud') ? 15 : 0) + ($has('amber') ? 15 : 0) + ($has('floral') ? 10 : 0) + ($has('sweet') ? 10 : 0) + ($has('spicy') ? 10 : 0) + ($hours >= 8 ? 10 : 0); break;
            case 'gift': $v = 50 + ($rating >= 4.5 ? 15 : 0) + ($rank >= 0.8 ? 15 : 0) + ((int)$p['rating_n'] >= 20 ? 5 : 0); break;
            case 'best-sellers': $v = 20 + (int)round(70 * $rank); break;
            case 'long-lasting': $v = $hours >= 10 ? 95 : ($hours >= 8 ? 85 : ($hours >= 6 ? 65 : ($hours > 0 ? 45 : 35))); break;
            case 'fresh': $c = (int)$has('citrus') + (int)$has('aquatic') + (int)$has('fresh'); $v = [20, 65, 80, 90][$c] - (($has('sweet') || $has('oud')) ? 15 : 0); break;
            case 'sweet': $v = $has('sweet') ? 80 + ($fam['sweet'] >= 2 ? 10 : 0) : 20; break;
            case 'woody': $v = ($has('woody') || $has('oud')) ? 80 : 20; break;
            case 'floral': $v = $has('floral') ? 80 : 20; break;
            case 'fruity': $v = $has('fruity') ? 80 : 20; break;
            case 'spicy': $v = $has('spicy') ? 80 : 20; break;
            case 'bold': $v = ($has('spicy') || $has('oud') || $has('leather') || $has('woody')) ? 70 + ($p['gender'] === 'men' ? 10 : 0) : 30; break;
            case 'compliments': $v = 40 + ($rating >= 4.5 ? 20 : 0) + (int)round(20 * $rank); break;
            case 'budget': $v = $price <= 0 ? 40 : ($price <= 999 ? 90 : ($price <= 1299 ? 70 : ($price <= 1999 ? 50 : 30))); break;
            case 'gym': $v = $cool ? 75 : 30; break;
            case 'travel': $v = $cool ? 60 : 40; break;
            case 'college': $v = ($cool || $has('fruity') || $has('sweet')) ? 60 : 35; break;
            case 'celebrity': $v = 25; break;
            case 'daily': $v = (($cool || $has('woody')) && $hours <= 8) ? 60 : 40; break;
            case 'middle-eastern': $v = ($has('oud') || $has('amber') || $has('spicy')) ? 70 : 25; break;
            case 'recommended': $v = 20; break;
            default: $re = wa_prod_occ_re($o); $hay = wa_prod_fold((string)$p['name'] . ' ' . $p['scent'] . ' ' . implode(' ', $cats), false); $v = @preg_match($re, $hay) ? 75 : 20;
        }
        if (isset($catSlugs[$s])) $v = max($v, in_array($s, ['best-sellers', 'celebrity', 'middle-eastern', 'recommended'], true) ? 92 : 90);
        $m[$s] = max(0, min(100, (int)$v));
    }
    return $m;
}
/** Rule fit + AI fit merged, tags, split notes, draft original. Cheap; runs after every sync, mining and AI batch. */
function wa_prod_retag(PDO $pdo): int
{
    $occ = wa_prod_occ_rows($pdo); $rows = $pdo->query("SELECT * FROM wa_prod WHERE active = 1")->fetchAll(); if (!$rows) return 0;
    $perf = array_values(array_filter($rows, fn($r) => $r['kind'] === 'perfume')); usort($perf, fn($a, $b) => ((int)$a['units_sold'] <=> (int)$b['units_sold']) ?: ((int)$a['id'] <=> (int)$b['id']));
    $rank = []; $cnt = max(1, count($perf) - 1); foreach ($perf as $i => $r) $rank[(int)$r['id']] = count($perf) > 1 ? $i / $cnt : 0.5;
    $upd = $pdo->prepare("UPDATE wa_prod SET fit_json = ?, occasions_json = ?, notes_top = ?, notes_mid = ?, notes_base = ?, notes_split = ?, original_real = ?, orig_conf = ?, search_txt = ? WHERE id = ?"); $n = 0;
    foreach ($rows as $p) {
        $fit = wa_prod_rule_fit($p, $occ, $rank[(int)$p['id']] ?? 0.5); $ai = json_decode((string)$p['ai_json'], true); $ai = is_array($ai) ? $ai : [];
        if (!empty($ai['fit']) && is_array($ai['fit'])) {
            $cats = []; foreach ((array)json_decode((string)$p['cats_json'], true) as $c) foreach (wa_prod_cat_slugs((string)$c) as $s) $cats[$s] = 1;
            foreach ($ai['fit'] as $s => $v) if (isset($fit[$s])) $fit[$s] = max(isset($cats[$s]) ? 90 : 0, max(0, min(100, (int)$v)));
        }
        arsort($fit); $tags = []; foreach ($fit as $s => $v) if ($v >= 70 && count($tags) < 20) $tags[] = $s;
        $notes = (array)(json_decode((string)$p['notes_json'], true) ?: []);
        if ((int)$p['notes_split'] >= 2) { $t = (string)$p['notes_top']; $mi = (string)$p['notes_mid']; $b = (string)$p['notes_base']; $sp = (int)$p['notes_split']; }
        else { [$t, $mi, $b] = wa_prod_split_notes($notes); $sp = $notes ? 1 : 0; }
        $real = (string)$p['original_real']; $conf = (int)$p['orig_conf'];
        if ($conf <= 45 && !empty($ai['real']) && (int)($ai['conf'] ?? 0) >= 50) { $real = (string)$ai['real']; $conf = (int)$ai['conf']; }
        if ($conf <= 45) {
            $d = wa_prod_rule_original((string)$p['original_site']);
            if ($d !== '') { $real = $d; $conf = 40; } elseif ($real !== '' && $conf === 40) { $real = ''; $conf = 0; }
        }
        $upd->execute([json_encode($fit), json_encode($tags), $t, $mi, $b, $sp, mb_substr($real, 0, 160), $conf, mb_substr(wa_prod_search_txt($p, $real), 0, 1500), $p['id']]); $n++;
    }
    return $n;
}
/** A readable original from the website spelling when it is clearly just leet-speak ("Cr33d Gr33n !r!sh Tw33d" → "Creed Green Irish Tweed"). '' when it is an abbreviation. */
function wa_prod_rule_original(string $site): string
{
    $site = trim($site); if ($site === '') return ''; $f = wa_prod_fold($site); $w = $f === '' ? [] : explode(' ', $f); if (count($w) < 2) return '';
    $good = 0; foreach ($w as $x) if (strlen($x) >= 3 && preg_match('/[aeiouy]/', $x)) $good++;
    if ($good / count($w) < 0.66) return '';
    return mb_convert_case(str_replace(' and ', ' & ', $f), MB_CASE_TITLE, 'UTF-8');
}
/** Everything a staff search should match for one product, folded. */
function wa_prod_search_txt(array $p, string $real = ''): string
{
    $parts = [wa_prod_fold((string)$p['name']), wa_prod_fold((string)$p['original_site']), wa_prod_fold($real !== '' ? $real : (string)$p['original_real'])];
    $first = wa_prod_words((string)$p['title'])[0] ?? ''; $h = wa_prod_houses(); if ($first !== '' && isset($h[$first])) $parts[] = wa_prod_fold($h[$first]);
    foreach (wa_prod_words((string)$p['name']) as $w) if (isset($h[$w])) $parts[] = wa_prod_fold($h[$w]);
    return implode(' | ', array_values(array_unique(array_filter($parts))));
}

// ------------------------------------------------------------------------------------------------------------------ sync
/** Reads one of our own settings straight from the table (setting_get() keeps a per-request copy that does not see what we just wrote). */
function wa_prod_cfg(PDO $pdo, string $k, string $def = ''): string
{
    try { $q = $pdo->prepare("SELECT setting_value FROM hrms_settings WHERE setting_key = ?"); $q->execute([$k]); $v = $q->fetchColumn(); return $v === false || $v === null ? $def : (string)$v; } catch (Throwable $e) { return $def; }
}
function wa_prod_unlock(PDO $pdo, string $name): void { try { setting_set($pdo, 'wa_lock_' . $name, '0'); } catch (Throwable $e) { } }
/** Reads every active product from Shopify (or from $fetch — a callable(?string $cursor): array like wa_prod_fetch_shopify) and makes the local table match.
 *  Idempotent. Removed products become active = 0, but only when the read was complete. */
function wa_prod_sync(PDO $pdo, ?callable $fetch = null, array $opt = []): array
{
    $res = ['ok' => false, 'error' => '', 'added' => 0, 'updated' => 0, 'deactivated' => 0, 'total' => 0, 'pages' => 0, 'incomplete' => false, 'at' => date('Y-m-d H:i:s')];
    if (!wa_prod_tables($pdo)) { $res['error'] = 'Product storage is not available — check database permissions.'; return $res; }
    $lock = empty($opt['nolock']); if ($lock && !wa_lock($pdo, 'prod_sync', 600)) { $res['error'] = 'A refresh is already running. Wait a minute.'; $res['busy'] = true; return $res; }
    $t0 = microtime(true);
    try {
        $fetch = $fetch ?: 'wa_prod_fetch_shopify'; $all = []; $cursor = null; $pages = 0; $more = false;
        do {
            $r = $fetch($cursor); $pages++;
            if (!empty($r['error'])) { $res['error'] = (string)$r['error']; setting_set($pdo, 'wa_prod_sync_info', json_encode($res + ['failed' => true])); return $res; }
            foreach ((array)($r['products'] ?? []) as $p) if (is_array($p)) $all[] = $p;
            $more = !empty($r['hasNext']); $cursor = isset($r['cursor']) ? (string)$r['cursor'] : null;
            if ($more && ($cursor === null || $cursor === '') && empty($r['products'])) { $more = false; }
        } while ($more && $pages < 150);
        $res['pages'] = $pages; $res['incomplete'] = $more;
        $rows = []; foreach ($all as $p) { $n = wa_prod_normalize($p); if ($n['shopify_id'] > 0) $rows[$n['shopify_id']] = $n; }
        if (!$rows) { $res['error'] = 'Shopify returned no products. Nothing was changed.'; setting_set($pdo, 'wa_prod_sync_info', json_encode($res + ['failed' => true])); return $res; }
        $old = []; foreach ($pdo->query("SELECT * FROM wa_prod")->fetchAll() as $o) $old[(int)$o['shopify_id']] = $o;
        $now = date('Y-m-d H:i:s');
        $ins = $pdo->prepare("INSERT INTO wa_prod (shopify_id, handle, title, name, original_site, gender, scent, notes_json, longevity, seasons_json, photos_json, price_text, price_min, price_max, our_price, our_ml, units_sold, rating, rating_n, kind, active, url, cats_json, tags_txt, best_for, src_hash, synced_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $upd = $pdo->prepare("UPDATE wa_prod SET handle = ?, title = ?, name = ?, original_site = ?, gender = ?, scent = ?, notes_json = ?, longevity = ?, seasons_json = ?, photos_json = ?, price_text = ?, price_min = ?, price_max = ?, our_price = ?, our_ml = ?, units_sold = ?, rating = ?, rating_n = ?,
            kind = ?, active = ?, url = ?, cats_json = ?, tags_txt = ?, best_for = ?, src_hash = ?, synced_at = ?, updated_at = ?, notes_split = ?, original_real = ?, orig_conf = ?, ai_json = ? WHERE id = ?");
        $sigOf = fn(array $a) => md5(json_encode([$a['photos'], $a['price_text'], (int)$a['units_sold'], (float)$a['rating'], $a['longevity'], (int)$a['active'], $a['url']]));
        foreach ($rows as $sid => $n) {
            $vals = [$n['handle'], $n['title'], $n['name'], $n['original_site'], $n['gender'], $n['scent'], json_encode($n['notes'], JSON_UNESCAPED_UNICODE), $n['longevity'], json_encode($n['seasons']), json_encode($n['photos']), $n['price_text'], $n['price_min'], $n['price_max'], $n['our_price'], $n['our_ml'],
                $n['units_sold'], $n['rating'], $n['rating_n'], $n['kind'], $n['active'], $n['url'], json_encode($n['cats'], JSON_UNESCAPED_UNICODE), $n['tags_txt'], $n['best_for'], $n['src_hash']];
            if (!isset($old[$sid])) { $ins->execute(array_merge([$sid], $vals, [$now, $now])); $res['added']++; continue; }
            $o = $old[$sid]; $oldSig = md5(json_encode([(array)json_decode((string)$o['photos_json'], true), $o['price_text'], (int)$o['units_sold'], (float)$o['rating'], $o['longevity'], (int)$o['active'], $o['url']]));
            $changed = $o['src_hash'] !== $n['src_hash'] || $oldSig !== $sigOf($n) || (int)$o['active'] === 0;
            $split = (int)$o['notes_split']; $real = (string)$o['original_real']; $conf = (int)$o['orig_conf']; $ai = (string)$o['ai_json'];
            if ($o['src_hash'] !== $n['src_hash']) { $split = 0; if ($o['original_site'] !== $n['original_site']) { $real = ''; $conf = 0; $ai = ''; } }
            $upd->execute(array_merge($vals, [$now, $changed ? $now : (string)($o['updated_at'] ?: $now), $split, $real, $conf, $ai, $o['id']]));
            if ($changed) $res['updated']++;
        }
        // every Shopify metafield + description; an original name written in Shopify is the truth (orig_conf 100 — Claude / the web never overwrite it)
        $ex = $pdo->prepare("UPDATE wa_prod SET mf_json = ?, descr = ?, orig_shop = ? WHERE shopify_id = ?");
        foreach ($rows as $sid => $n) $ex->execute([json_encode($n['mf'], JSON_UNESCAPED_UNICODE), $n['descr'], mb_substr($n['orig_shop'], 0, 160), $sid]);
        $pdo->exec("UPDATE wa_prod SET original_real = orig_shop, orig_conf = 100 WHERE orig_shop <> ''");
        $pdo->exec("UPDATE wa_prod SET original_real = '', orig_conf = 0 WHERE orig_shop = '' AND orig_conf = 100");
        // products no longer on the site
        $seen = array_keys($rows); $activeBefore = 0; foreach ($old as $o) if ((int)$o['active'] === 1) $activeBefore++;
        if (!$res['incomplete'] && $activeBefore > 0 && count($seen) >= (int)ceil($activeBefore * 0.5)) {
            foreach ($old as $sid => $o) if ((int)$o['active'] === 1 && !isset($rows[$sid])) { $pdo->prepare("UPDATE wa_prod SET active = 0, updated_at = ? WHERE id = ?")->execute([$now, $o['id']]); $res['deactivated']++; }
        } elseif ($activeBefore > 0 && count($seen) < (int)ceil($activeBefore * 0.5)) {
            $res['warning'] = 'Shopify returned far fewer products than before, so nothing was switched off.';
        }
        wa_prod_occ_seed($pdo); wa_prod_snapshots($pdo); wa_prod_retag($pdo);
        $res['total'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_prod WHERE active = 1")->fetchColumn(); $res['ok'] = true; $res['ms'] = (int)round((microtime(true) - $t0) * 1000);
        setting_set($pdo, 'wa_prod_last_sync', date('Y-m-d', (int)($opt['ts'] ?? time()))); setting_set($pdo, 'wa_prod_last_sync_at', $now); setting_set($pdo, 'wa_prod_sync_info', json_encode($res));
        return $res;
    } catch (Throwable $e) {
        error_log('wa_prod_sync: ' . $e->getMessage()); $res['error'] = 'The refresh stopped: ' . mb_substr($e->getMessage(), 0, 160); return $res;
    } finally { if ($lock) wa_prod_unlock($pdo, 'prod_sync'); }
}
/** Daily copy of units_sold per product, and the 30 / 90-day sales that follow from it (NULL until there are at least 7 days of history). */
function wa_prod_snapshots(PDO $pdo): void
{
    $today = date('Y-m-d');
    $pdo->prepare("INSERT INTO wa_prod_snap (day, prod_id, units) SELECT ?, id, units_sold FROM wa_prod WHERE active = 1 ON DUPLICATE KEY UPDATE units = VALUES(units)")->execute([$today]);
    $pdo->prepare("DELETE FROM wa_prod_snap WHERE day < ?")->execute([date('Y-m-d', time() - 130 * 86400)]);
    $pdo->exec("UPDATE wa_prod SET units_30d = NULL, units_90d = NULL");
    foreach ([30 => 'units_30d', 90 => 'units_90d'] as $days => $col) {
        $from = date('Y-m-d', time() - $days * 86400); $latest = date('Y-m-d', time() - 7 * 86400);
        $pdo->prepare("UPDATE wa_prod p JOIN (SELECT s.prod_id, s.units FROM wa_prod_snap s JOIN (SELECT prod_id, MIN(day) d FROM wa_prod_snap WHERE day >= ? GROUP BY prod_id) m ON m.prod_id = s.prod_id AND m.d = s.day WHERE s.day <= ?) b ON b.prod_id = p.id
            SET p.$col = GREATEST(0, p.units_sold - b.units) WHERE p.active = 1")->execute([$from, $latest]);
    }
}

// ------------------------------------------------------------------------------------------------------------------ mining the past chats for new request types
function wa_prod_first_id_since(PDO $pdo, int $ts): int
{
    $lo = (int)$pdo->query("SELECT MIN(id) FROM wa_messages")->fetchColumn(); $hi = (int)$pdo->query("SELECT MAX(id) FROM wa_messages")->fetchColumn(); if ($hi <= 0) return 0;
    $cut = date('Y-m-d H:i:s', $ts); $q = $pdo->prepare("SELECT ts FROM wa_messages WHERE id >= ? ORDER BY id LIMIT 1");
    while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); $q->execute([$mid]); $t = (string)$q->fetchColumn(); if ($t === '' || $t >= $cut) $hi = $mid; else $lo = $mid + 1; }
    return $lo;
}
/** Counts how often customers ask for each request type and finds new phrases ("best gym perfume", "wedding wala perfume"). A phrase seen $promote times becomes a new request type. */
function wa_prod_mine(PDO $pdo, array $opt = []): array
{
    $max = (int)($opt['max'] ?? 60000); $promote = (int)($opt['promote'] ?? 6); $days = (int)($opt['days'] ?? 120);
    $out = ['scanned' => 0, 'counts' => [], 'promoted' => [], 'last_id' => 0];
    $last = (int)wa_prod_cfg($pdo, 'wa_prod_mine_id', '0');
    if ($last <= 0 || !empty($opt['restart'])) $last = max(0, wa_prod_first_id_since($pdo, time() - $days * 86400) - 1);
    $regs = []; foreach (wa_prod_occ_rows($pdo, true) as $o) $regs[(string)$o['slug']] = wa_prod_occ_re($o);
    $phr = json_decode((string)wa_prod_cfg($pdo, 'wa_prod_phr', '{}'), true); if (!is_array($phr)) $phr = [];
    $stop = wa_prod_stop() + array_flip(['men', 'women', 'man', 'woman', 'boys', 'girls', 'him', 'her', 'ladies', 'gents', 'my', 'me', 'us', 'we', 'good', 'nice', 'great', 'cheap', 'price', 'quality', 'original', 'duplicate', 'real', 'first', 'copy']);
    $pats = ['/\bbest ([a-z]+(?: [a-z]+)?) (?:perfumes?|fragrances?|scents?|attars?)\b/', '/\b(?:perfumes?|fragrances?|scents?|attars?) for ([a-z]+(?: [a-z]+)?)\b/', '/\b([a-z]+(?: [a-z]+)?) wal[ai] (?:perfumes?|attars?|scents?)\b/'];
    $q = $pdo->prepare("SELECT id, body FROM wa_messages WHERE id > ? AND dir = 'in' AND mtype = 'text' ORDER BY id LIMIT 3000"); $counts = [];
    while ($out['scanned'] < $max) {
        $q->execute([$last]); $rows = $q->fetchAll(); if (!$rows) break;
        foreach ($rows as $r) {
            $last = (int)$r['id']; $out['scanned']++; $f = wa_prod_fold((string)$r['body'], false); if ($f === '' || strlen($f) > 300) continue;
            foreach ($regs as $slug => $re) if (@preg_match($re, $f)) $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            foreach ($pats as $pt) if (preg_match($pt, $f, $m)) {
                $w = explode(' ', trim($m[1])); while ($w && isset($stop[$w[0]])) array_shift($w); while ($w && isset($stop[end($w)])) array_pop($w);
                $c = implode(' ', $w); if ($c === '' || strlen($c) < 3 || strlen($c) > 24 || count($w) > 3 || preg_match('/\d/', $c)) continue;
                $known = false; foreach ($regs as $re) if (@preg_match($re, $c)) { $known = true; break; } if ($known) continue;
                $phr[$c] = ($phr[$c] ?? 0) + 1;
            }
        }
    }
    $out['last_id'] = $last; setting_set($pdo, 'wa_prod_mine_id', (string)$last);
    $up = $pdo->prepare("UPDATE wa_prod_occ SET hits = hits + ? WHERE slug = ?"); foreach ($counts as $slug => $n) $up->execute([$n, $slug]); $out['counts'] = $counts;
    $ins = $pdo->prepare("INSERT IGNORE INTO wa_prod_occ (slug, label, hits, source, active, grp, kw, sort) VALUES (?, ?, ?, 'chat', 1, 'occ', ?, 900)");
    foreach ($phr as $c => $n) {
        if ($n < $promote) continue; $slug = wa_prod_slug((string)$c); if ($slug === '') { unset($phr[$c]); continue; }
        $ins->execute([$slug, mb_convert_case((string)$c, MB_CASE_TITLE, 'UTF-8'), $n, preg_quote((string)$c, '/')]); if ($ins->rowCount() > 0) $out['promoted'][] = $slug; unset($phr[$c]);
    }
    arsort($phr); setting_set($pdo, 'wa_prod_phr', json_encode(array_slice($phr, 0, 300), JSON_UNESCAPED_UNICODE));
    if ($out['promoted']) wa_prod_retag($pdo);
    return $out;
}

// ------------------------------------------------------------------------------------------------------------------ AI enrichment (batches of 15, cached, only when something changed or a new request type appeared)
function wa_prod_pending(PDO $pdo, int $limit = 15): array
{
    $h = wa_prod_occ_hash($pdo);
    $q = $pdo->prepare("SELECT * FROM wa_prod WHERE active = 1 AND kind = 'perfume' AND (enriched_at IS NULL OR enr_hash <> src_hash OR enr_occ <> ?) ORDER BY units_sold DESC, id LIMIT " . max(1, $limit)); $q->execute([$h]);
    return $q->fetchAll();
}
function wa_prod_pending_count(PDO $pdo): int
{
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_prod WHERE active = 1 AND kind = 'perfume' AND (enriched_at IS NULL OR enr_hash <> src_hash OR enr_occ <> ?)"); $q->execute([wa_prod_occ_hash($pdo)]); return (int)$q->fetchColumn();
}
function wa_prod_enrich(PDO $pdo, int $limit = 15, array $opt = []): array
{
    $res = ['ok' => false, 'done' => 0, 'left' => 0, 'error' => '']; if (!wa_prod_tables($pdo)) { $res['error'] = 'Product storage is not available.'; return $res; }
    $wait = (int)wa_prod_cfg($pdo, 'wa_prod_ai_wait', '0'); if ($wait > time() && empty($opt['force'])) { $res['left'] = wa_prod_pending_count($pdo); $res['error'] = (string)wa_prod_cfg($pdo, 'wa_prod_ai_err', ''); $res['skipped'] = true; return $res; }
    $rows = wa_prod_pending($pdo, min(15, $limit)); $res['left'] = wa_prod_pending_count($pdo); if (!$rows) { $res['ok'] = true; return $res; }
    $occ = wa_prod_occ_rows($pdo, true); $slugs = []; foreach ($occ as $o) if (($o['grp'] ?? 'occ') !== 'who') $slugs[] = $o['slug'] . ' (' . $o['label'] . ')';
    $items = []; foreach ($rows as $p) $items[] = ['id' => (int)$p['id'], 'our_name' => $p['name'], 'website_original_name' => $p['original_site'], 'gender' => $p['gender'], 'notes_in_order' => (array)json_decode((string)$p['notes_json'], true), 'seasons' => (array)json_decode((string)$p['seasons_json'], true),
        'site_categories' => (array)json_decode((string)$p['cats_json'], true), 'scent_profile' => mb_substr((string)$p['scent'], 0, 200), 'longevity' => $p['longevity']];
    $sys = "You help a perfume shop (TM Perfume House, Surat, India) that sells its own inspired-by versions of famous designer perfumes. The website writes the original perfume name in a disguised way on purpose (for example 'D COOL WTR', 'M0sch!n0 T0y B0y', 'AVENTS'). "
        . "For every product: (1) work out the REAL original perfume (brand + name). Give a confidence 0-100. If you are not sure use an empty string and confidence 0. (2) Split the given notes into top, middle and base notes (comma separated; use only the given notes; if unsure keep their order). "
        . "(3) Score how well the product fits each request type from 0 to 100. Be strict: 90 or more only for a clear fit. Use exactly the slugs given. Answer only JSON.\nRequest types: " . implode(', ', $slugs);
    $sch = ['type' => 'OBJECT', 'properties' => ['items' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['id' => ['type' => 'INTEGER'], 'original' => ['type' => 'STRING'], 'confidence' => ['type' => 'INTEGER'], 'top' => ['type' => 'STRING'], 'mid' => ['type' => 'STRING'], 'base' => ['type' => 'STRING'],
        'fit' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['slug' => ['type' => 'STRING'], 'score' => ['type' => 'INTEGER']], 'required' => ['slug', 'score']]]], 'required' => ['id']]]], 'required' => ['items']];
    [$ok, $v, $why] = wa_prod_ai($sys, [['text' => json_encode($items, JSON_UNESCAPED_UNICODE)]], $sch, 9000);
    if (!$ok || !is_array($v) || !isset($v['items']) || !is_array($v['items'])) {
        $res['error'] = $ok ? 'The AI answered in a form we could not read.' : (string)($why ?: 'The AI did not answer.');
        setting_set($pdo, 'wa_prod_ai_err', mb_substr($res['error'], 0, 200)); setting_set($pdo, 'wa_prod_ai_wait', (string)(time() + 6 * 3600)); return $res;
    }
    $byId = []; foreach ($rows as $p) $byId[(int)$p['id']] = $p; $valid = []; foreach ($occ as $o) $valid[$o['slug']] = 1; $now = date('Y-m-d H:i:s'); $h = wa_prod_occ_hash($pdo);
    $upd = $pdo->prepare("UPDATE wa_prod SET ai_json = ?, original_real = ?, orig_conf = ?, notes_top = ?, notes_mid = ?, notes_base = ?, notes_split = ?, enr_hash = ?, enr_occ = ?, enriched_at = ? WHERE id = ?");
    $got = []; foreach ($v['items'] as $it) if (is_array($it) && isset($byId[(int)($it['id'] ?? 0)])) $got[(int)$it['id']] = $it;
    foreach ($byId as $id => $p) {
        $it = $got[$id] ?? null; $ai = []; $real = (string)$p['original_real']; $conf = (int)$p['orig_conf']; $t = (string)$p['notes_top']; $m = (string)$p['notes_mid']; $b = (string)$p['notes_base']; $sp = (int)$p['notes_split'];
        if ($it) {
            $c = max(0, min(100, (int)($it['confidence'] ?? 0))); $orig = trim(preg_replace('/\s+/u', ' ', (string)($it['original'] ?? '')) ?? '');
            if ($conf >= 95) { } elseif ($orig !== '' && $c >= 50) { $real = mb_substr($orig, 0, 160); $conf = min(90, $c); $ai['real'] = $real; $ai['conf'] = $conf; } elseif ($conf > 45) { $real = ''; $conf = 0; }   // 100 = written in Shopify, 95 = found on the web: kept
            $notes = array_map('mb_strtolower', (array)json_decode((string)$p['notes_json'], true)); $tt = trim((string)($it['top'] ?? '')); $mm = trim((string)($it['mid'] ?? '')); $bb = trim((string)($it['base'] ?? ''));
            $all = array_filter(array_map('trim', explode(',', $tt . ',' . $mm . ',' . $bb))); $okN = $all ? true : false; foreach ($all as $x) if (!in_array(mb_strtolower($x), $notes, true)) { $okN = false; break; }
            if ($okN) { $t = mb_substr($tt, 0, 300); $m = mb_substr($mm, 0, 300); $b = mb_substr($bb, 0, 300); $sp = 2; }
            $fit = []; foreach ((array)($it['fit'] ?? []) as $f) if (is_array($f) && isset($valid[(string)($f['slug'] ?? '')])) $fit[(string)$f['slug']] = max(0, min(100, (int)($f['score'] ?? 0))); if ($fit) $ai['fit'] = $fit;
        }
        $upd->execute([json_encode($ai, JSON_UNESCAPED_UNICODE), $real, $conf, $t, $m, $b, $sp, $p['src_hash'], $h, $now, $id]); $res['done']++;
    }
    wa_prod_retag($pdo); setting_set($pdo, 'wa_prod_ai_err', ''); setting_set($pdo, 'wa_prod_ai_wait', '0'); $res['ok'] = true; $res['left'] = wa_prod_pending_count($pdo);
    return $res;
}

// ------------------------------------------------------------------------------------------------------------------ the daily job (06:30 IST) — called from fu_housekeeping() (the wa.cron tick)
/** Runs the whole job NOW (sync, mining, one AI batch). Used by the cron hook (after the response) and by tests. */
function wa_prod_daily(PDO $pdo, array $opt = []): array
{
    $o = ['sync' => null, 'mine' => null, 'enrich' => null];
    if (!wa_prod_tables($pdo)) return ['error' => 'tables'];
    if (!empty($opt['sync_due'])) {
        setting_set($pdo, 'wa_prod_try_at', (string)time());
        $o['sync'] = wa_prod_sync($pdo, $opt['fetch'] ?? null, $opt);
        if (!empty($o['sync']['ok'])) { try { $o['mine'] = wa_prod_mine($pdo, $opt['mine'] ?? []); } catch (Throwable $e) { $o['mine'] = ['error' => $e->getMessage()]; } }
    }
    try { $o['enrich'] = wa_prod_enrich($pdo, 15, $opt); } catch (Throwable $e) { $o['enrich'] = ['error' => $e->getMessage()]; }
    if (empty($opt['no_web'])) { try { $o['web'] = wa_prod_web_originals($pdo, 10); } catch (Throwable $e) { $o['web'] = ['error' => $e->getMessage()]; } try { $o['fill'] = wa_prod_web_fill($pdo, 10); } catch (Throwable $e) { $o['fill'] = ['error' => $e->getMessage()]; } }
    return $o;
}
/** Cheap check done on every cron tick. After 06:30 IST and not yet synced today → the job runs once (after the response, so campaign sending is never slowed down). Returns 'skip' | 'ran' | 'queued' | 'busy'. */
function wa_prod_cron(PDO $pdo, array $opt = []): string
{
    if (!wa_prod_tables($pdo)) return 'skip';
    if ((string)wa_prod_cfg($pdo, 'wa_prod_auto', '1') === '0') return 'off';   // the Products tab switch "Refresh every morning"
    $ts = (int)($opt['ts'] ?? time()); $today = date('Y-m-d', $ts);
    $due = date('H:i', $ts) >= '06:30' && (string)wa_prod_cfg($pdo, 'wa_prod_last_sync', '') !== $today && ((int)wa_prod_cfg($pdo, 'wa_prod_try_at', '0') < $ts - 3 * 3600 || !empty($opt['force']));
    // 📸 once, right after the collage release (owner, 10 Nov): pull every perfume's photos / notes / longevity from Shopify now, without waiting for the next 06:30
    $once = (string)wa_prod_cfg($pdo, 'wa_prod_once_collage', '') === '' && (int)wa_prod_cfg($pdo, 'wa_prod_try_at', '0') < $ts - 600; if ($once) $due = true;
    $backlog = !$due && (int)wa_prod_cfg($pdo, 'wa_prod_ai_wait', '0') <= $ts && (int)$pdo->query("SELECT COUNT(*) FROM wa_prod")->fetchColumn() > 0 && wa_prod_pending_count($pdo) > 0;
    if (!$due && !$backlog) return 'skip';
    if (!wa_lock($pdo, 'prod_daily', 900)) return 'busy';
    $run = function () use ($pdo, $due, $opt, $once) { try { @ignore_user_abort(true); @set_time_limit(280); $rr = wa_prod_daily($pdo, ['sync_due' => $due, 'nolock' => false] + $opt); if ($once && !empty($rr['sync']['ok'])) setting_set($pdo, 'wa_prod_once_collage', date('Y-m-d H:i:s')); } catch (Throwable $e) { error_log('wa_prod_cron: ' . $e->getMessage()); } finally { wa_prod_unlock($pdo, 'prod_daily'); } };
    if (!empty($opt['inline'])) { $run(); return 'ran'; }
    register_shutdown_function(function () use ($run) { if (function_exists('hrms_release')) hrms_release(); elseif (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); $run(); });
    return 'queued';
}

// ------------------------------------------------------------------------------------------------------------------ reading the table
/** Decodes the JSON columns of one row (safe to call twice). */
function wa_prod_dec(array $p): array
{
    if (isset($p['_d'])) return $p;
    foreach (['notes' => 'notes_json', 'seasons' => 'seasons_json', 'photos' => 'photos_json', 'cats' => 'cats_json', 'fit' => 'fit_json', 'tags' => 'occasions_json'] as $k => $col) { $v = json_decode((string)($p[$col] ?? ''), true); $p[$k] = is_array($v) ? $v : []; }
    $p['id'] = (int)$p['id']; $p['units_sold'] = (int)$p['units_sold']; $p['units_90d'] = $p['units_90d'] === null ? null : (int)$p['units_90d']; $p['units_30d'] = ($p['units_30d'] ?? null) === null ? null : (int)$p['units_30d'];
    $mf = json_decode((string)($p['mf_json'] ?? ''), true); $p['mf'] = is_array($mf) ? $mf : [];
    $wf = json_decode((string)($p['web_json'] ?? ''), true);   // gaps Shopify leaves empty, filled from the internet with the real original name (Shopify's own values always win)
    if (is_array($wf)) { if (trim((string)($p['longevity'] ?? '')) === '' && !empty($wf['longevity'])) { $p['longevity'] = (string)$wf['longevity']; $p['longevity_web'] = 1; }
        if (!$p['notes'] && !empty($wf['notes'])) { $p['notes'] = (array)$wf['notes']; $p['notes_web'] = 1; } if (trim((string)($p['gender'] ?? '')) === '' && !empty($wf['gender'])) $p['gender'] = (string)$wf['gender']; }
    $ow = json_decode((string)($p['own_json'] ?? ''), true);   // the owner's own corrections ("Rasasi Hawas lasts 6-8 hrs") win over Shopify and the web
    if (is_array($ow)) { if (!empty($ow['longevity'])) $p['longevity'] = (string)$ow['longevity']; if (!empty($ow['notes'])) $p['notes'] = (array)$ow['notes']; if (!empty($ow['gender'])) $p['gender'] = (string)$ow['gender'];
        if (!empty($ow['original'])) { $p['original_real'] = (string)$ow['original']; $p['orig_conf'] = 100; } $p['own'] = $ow; }
    $p['rating'] = (float)$p['rating']; $p['_d'] = 1; return $p;
}
/** All active products, decoded (cached for the request). */
function wa_prod_all(PDO $pdo, bool $fresh = false): array
{
    static $c = null; if ($c !== null && !$fresh) return $c; if (!wa_prod_tables($pdo)) return $c = [];
    $c = []; foreach ($pdo->query("SELECT * FROM wa_prod WHERE active = 1 ORDER BY units_sold DESC, id")->fetchAll() as $r) $c[] = wa_prod_dec($r);
    return $c;
}
function wa_prod_get(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return [];
    $q = $pdo->query("SELECT * FROM wa_prod WHERE id IN (" . implode(',', $ids) . ")"); $by = []; foreach ($q->fetchAll() as $r) $by[(int)$r['id']] = wa_prod_dec($r);
    $o = []; foreach ($ids as $i) if (isset($by[$i])) $o[] = $by[$i]; return $o;
}

// ------------------------------------------------------------------------------------------------------------------ 🌿 find by notes
/** Note families: a customer's "fresh citrus" matches lemon / bergamot…; a note of the same family counts half when the exact one is missing. */
function wa_prod_note_families(): array
{
    return ['citrus' => ['citrus', 'lemon', 'bergamot', 'orange', 'mandarin', 'tangerine', 'grapefruit', 'lime', 'citron', 'yuzu', 'calabrian'], 'floral' => ['floral', 'flower', 'rose', 'jasmine', 'magnolia', 'honeysuckle', 'lily', 'tuberose', 'peony', 'violet', 'iris', 'orris', 'orange blossom', 'neroli', 'ylang', 'gardenia', 'freesia', 'lilac', 'geranium', 'osmanthus', 'mimosa', 'lotus'],
        'woody' => ['woody', 'wood', 'cedar', 'sandalwood', 'vetiver', 'patchouli', 'oud', 'agarwood', 'cashmere', 'cashmeran', 'guaiac', 'birch', 'pine', 'cypress', 'oakmoss', 'moss'], 'musk' => ['musk', 'musky'],
        'amber' => ['amber', 'ambergris', 'ambroxan', 'ambrox', 'labdanum', 'benzoin', 'resin', 'incense', 'myrrh', 'frankincense'], 'sweet' => ['vanilla', 'tonka', 'caramel', 'praline', 'honey', 'sugar', 'chocolate', 'cocoa', 'coffee', 'almond', 'gourmand', 'sweet'],
        'spicy' => ['spicy', 'spice', 'pepper', 'cardamom', 'cinnamon', 'saffron', 'clove', 'nutmeg', 'ginger', 'cumin'], 'fresh' => ['fresh', 'aquatic', 'marine', 'sea', 'water', 'ozonic', 'mint', 'green', 'tea', 'cucumber', 'aldehyde'],
        'fruity' => ['fruity', 'fruit', 'apple', 'pear', 'peach', 'berry', 'berries', 'raspberry', 'strawberry', 'pineapple', 'blackcurrant', 'cassis', 'plum', 'cherry', 'coconut', 'melon', 'lychee', 'mango', 'fig'],
        'leather' => ['leather', 'suede', 'tobacco', 'smoky', 'smoke', 'birch tar'], 'aromatic' => ['lavender', 'sage', 'rosemary', 'basil', 'thyme', 'herbal', 'aromatic', 'artemisia']];
}
function wa_prod_note_norm(string $n): string { $n = mb_strtolower(trim($n)); $n = preg_replace('/\b(notes?|accords?|fresh|sparkling|juicy|soft|warm|white|pink|black|red|sicilian|italian|calabrian)\b/u', ' ', $n) ?: $n; return trim(preg_replace('/[^a-z]+/u', '', $n)); }
function wa_prod_note_list(string $s): array { $o = []; foreach (preg_split('/[,;\/\n]|\band\b|&|\+/iu', $s) as $x) { $x = trim(preg_replace('/^(top|heart|middle|mid|base)\s*notes?\s*:?/iu', '', trim($x))); if ($x !== '' && mb_strlen($x) <= 40) $o[] = $x; } return $o; }
function wa_prod_note_fam(string $note): array { $l = ' ' . mb_strtolower($note) . ' '; $l2 = preg_replace('/\b(fresh|sparkling|juicy|soft|warm|light|deep|dark|notes?|accords?)\b/u', ' ', $l); if (trim($l2) !== '') $l = $l2; $f = []; foreach (wa_prod_note_families() as $k => $ws) foreach ($ws as $w) if (strpos($l, $w) !== false) { $f[$k] = 1; break; } return array_keys($f); }
/** Our perfumes for a customer's top / heart / base notes: [items with 'match' %, 'matched', 'missing'], best first; ≥ 60 % only. $name: a perfume the customer named. */
function wa_prod_notes_match(PDO $pdo, string $top, string $mid, string $base, string $name = ''): array
{
    $want = []; foreach ([[$top, 0.8], [$mid, 1.0], [$base, 1.25]] as [$txt, $w]) foreach (wa_prod_note_list($txt) as $n) $want[] = ['n' => $n, 'k' => wa_prod_note_norm($n), 'fam' => wa_prod_note_fam($n), 'w' => $w];
    $nmK = wa_prod_note_norm($name); $all = wa_prod_all($pdo); $res = []; $noNotes = 0;
    foreach ($all as $p) {
        if (($p['kind'] ?? 'perfume') !== 'perfume' && ($p['kind'] ?? '') !== '') continue;
        $have = array_merge((array)$p['notes'], wa_prod_note_list((string)$p['notes_top'] . ',' . (string)$p['notes_mid'] . ',' . (string)$p['notes_base'])); if (!$have) { $noNotes++; continue; }
        $hk = []; $hf = []; foreach ($have as $h) { $k = wa_prod_note_norm((string)$h); if ($k !== '') $hk[$k] = (string)$h; foreach (wa_prod_note_fam((string)$h) as $f) $hf[$f] = 1; }
        $sum = 0; $got = 0; $ok = []; $miss = [];
        foreach ($want as $x) { $sum += $x['w']; $hit = 0.0;
            if ($x['k'] !== '') foreach ($hk as $k => $orig) if ($k === $x['k'] || (strlen($x['k']) >= 4 && (strpos($k, $x['k']) !== false || strpos($x['k'], $k) !== false))) { $hit = 1.0; break; }
            if ($hit < 1 && $x['fam']) { $generic = in_array($x['k'], ['citrus', 'citrusy', 'floral', 'florals', 'flower', 'flowers', 'woody', 'wood', 'woods', 'musk', 'musky', 'amber', 'ambery', 'sweet', 'spicy', 'spice', 'spices', 'aquatic', 'marine', 'green', 'fruity', 'fruit', 'fruits', 'leather', 'aromatic', 'herbal', 'gourmand', 'resin', 'resins', 'smoky'], true);
                foreach ($x['fam'] as $f) if (isset($hf[$f])) $hit = max($hit, $generic ? 1.0 : 0.5); }   // "citrus" asked → any citrus note is a full match; "magnolia" asked → another floral counts half
            $got += $hit * $x['w']; if ($hit >= 1) $ok[] = $x['n']; elseif ($hit > 0) $ok[] = '~' . $x['n']; else $miss[] = $x['n']; }
        $pct = $sum > 0 ? (int)round(100 * $got / $sum) : 0; $insp = false;
        if ($nmK !== '' && strlen($nmK) >= 5) { foreach ([(string)$p['original_real'], (string)$p['original_site']] as $o) { $ok2 = wa_prod_note_norm($o); if ($ok2 !== '' && (strpos($ok2, $nmK) !== false || strpos($nmK, $ok2) !== false)) { $insp = true; break; } } }
        if ($insp) $pct = max($pct, 100);
        if ($pct >= 20 || $insp) $res[] = ['p' => $p, 'pct' => min(100, $pct), 'ok' => $ok, 'miss' => $miss, 'insp' => $insp];
    }
    usort($res, fn($a, $b) => [$b['insp'], $b['pct'], (int)$b['p']['units_sold']] <=> [$a['insp'], $a['pct'], (int)$a['p']['units_sold']]);
    $good = array_values(array_filter($res, fn($r) => $r['pct'] >= 60 || $r['insp'])); $res = $good ?: array_slice($res, 0, 5);   // nothing ≥ 60 %: the 5 closest, with their %
    $items = []; foreach (array_slice($res, 0, 15) as $r) { $it = wa_prod_item($r['p'], $all, ['occ' => [], 'labels' => []], true); $it['match'] = $r['pct']; $it['matched'] = $r['ok']; $it['missing'] = $r['miss']; $it['inspired'] = $r['insp']; $items[] = $it; }
    return ['items' => $items, 'asked' => array_column($want, 'n'), 'no_notes' => $noNotes, 'total' => count($all)];
}

// ------------------------------------------------------------------------------------------------------------------ understanding a request
/** "winter perfume for men" → ['occ' => ['winter'], 'who' => 'men', 'words' => []] ; "aventus winter" → words ['aventus']. */
function wa_prod_parse(PDO $pdo, string $q): array
{
    $out = ['occ' => [], 'who' => '', 'words' => [], 'labels' => []];
    $fo = wa_prod_fold($q, false); if ($fo === '') return $out; $rest = ' ' . $fo . ' ';
    $consumed = [];
    foreach (wa_prod_occ_rows($pdo, true) as $o) {
        $re = wa_prod_occ_re($o); if (!@preg_match_all($re, $rest, $mm)) continue;
        foreach ($mm[0] as $hit) foreach (wa_prod_words((string)$hit) as $w) $consumed[$w] = 1;
        $rest = preg_replace($re, ' ', $rest) ?? $rest;
        if (($o['grp'] ?? 'occ') === 'who') { if ($out['who'] === '') $out['who'] = (string)$o['slug']; } else { $out['occ'][] = (string)$o['slug']; $out['labels'][] = (string)$o['label']; }
    }
    // the words that are left over (with leet decoded: "M0sch!n0") may be a product name
    $stop = wa_prod_stop(); $names = [];
    foreach (wa_prod_words($q) as $w) if (strlen($w) >= 2 && !isset($stop[$w]) && !isset($consumed[$w]) && !preg_match('/^\d+$/', $w)) $names[] = $w;
    if (!$names && !$out['occ'] && $out['who'] === '') {   // "a v e n t s": letters typed with spaces are one name
        $single = 0; foreach (preg_split('/\s+/', $fo) ?: [] as $t) if (strlen($t) === 1) $single++;
        $cq = str_replace(' ', '', wa_prod_fold($q)); if ($single >= 3 && strlen($cq) >= 4) $names[] = $cq;
    }
    $out['words'] = $names; return $out;
}
/** How well does a typed name match one product? 0-100. Handles real name, website spelling, our name; case, accents, spaces; partial words; one typo. */
function wa_prod_name_score(array $words, string $raw, array $p): float
{
    $st = (string)($p['search_txt'] ?? ''); if ($st === '') $st = wa_prod_search_txt($p);
    $fields = explode(' | ', $st); $qc = wa_prod_compact($raw); $best = 0.0;
    if (strlen($qc) >= 3) foreach ($fields as $f) { $fc = str_replace(' ', '', $f); if ($fc === '') continue; if ($fc === $qc) { $best = 100; break; } $pos = strpos($fc, $qc); if ($pos === 0) $best = max($best, 92); elseif ($pos !== false) $best = max($best, 80); }
    if ($best >= 100 || !$words) return $best;
    $tok = []; foreach ($fields as $f) foreach (explode(' ', $f) as $t) if ($t !== '') $tok[$t] = 1; $tok = array_keys($tok);
    $sum = 0.0; foreach ($words as $w) {
        $m = 0.0; $lw = strlen($w);
        foreach ($tok as $t) {
            if ($t === $w) { $m = 1.0; break; }
            if ($lw >= 3 && strncmp($t, $w, $lw) === 0) $m = max($m, 0.9);
            elseif ($lw >= 5 && abs(strlen($t) - $lw) <= 2 && levenshtein($w, $t) <= ($lw >= 8 ? 2 : 1)) $m = max($m, 0.7);
            elseif ($lw >= 4 && strlen($t) >= 4 && strncmp($t, $w, 3) === 0 && levenshtein($w, $t) <= 1) $m = max($m, 0.7);
        }
        if ($m <= 0) return $best; $sum += $m;
    }
    return max($best, 40 + 50 * ($sum / count($words)));
}

// ------------------------------------------------------------------------------------------------------------------ picking
function wa_prod_sales_key(array $rows): callable
{
    $use90 = true; foreach ($rows as $p) if ($p['units_90d'] === null) { $use90 = false; break; }
    return fn(array $p) => $use90 ? (int)$p['units_90d'] : (int)$p['units_sold'];
}
/** Place of a product among all perfumes by sales: [rank, 'this month'|'best seller']. */
function wa_prod_overall_rank(array $all, int $id): array
{
    $perf = array_values(array_filter($all, fn($p) => $p['kind'] === 'perfume')); $m = true; foreach ($perf as $p) if ($p['units_30d'] === null) { $m = false; break; }
    usort($perf, fn($a, $b) => ($m ? (int)$b['units_30d'] <=> (int)$a['units_30d'] : (int)$b['units_sold'] <=> (int)$a['units_sold']) ?: ((int)$a['id'] <=> (int)$b['id']));
    foreach ($perf as $i => $p) if ($p['id'] === $id) return [$i + 1, $m ? 'this month' : 'best seller'];
    return [0, ''];
}
/** A short, customer-safe reason: "warm, lasts 8-10 hrs, #1 for winter". Never invents anything; leaves out what is not known. */
function wa_prod_why(array $p, array $all, array $reqSlugs, array $labels = []): string
{
    $parts = []; $fam = wa_prod_fam_of($p['notes'], (string)$p['scent']); $adj = wa_prod_adj();
    $bestK = ''; $bestV = 0; foreach (['oud', 'leather', 'spicy', 'sweet', 'amber', 'woody', 'floral', 'fruity', 'citrus', 'aquatic', 'fresh'] as $k) { $v = ($fam[$k] ?? 0) + ($k === 'oud' ? 1 : 0); if ($v > $bestV) { $bestV = $v; $bestK = $k; } }
    if ($bestK !== '') $parts[] = $adj[$bestK];
    if ($p['longevity'] !== '') $parts[] = 'lasts ' . $p['longevity'];
    $slug = $reqSlugs[0] ?? '';
    if ($slug === 'best-sellers') { [$r, $lab] = wa_prod_overall_rank($all, $p['id']); if ($r > 0 && $r <= 10) $parts[] = '#' . $r . ' ' . $lab; }
    elseif ($slug !== '') {
        $lab = mb_strtolower($labels[0] ?? $slug); $in = array_values(array_filter($all, fn($x) => $x['kind'] === 'perfume' && (int)($x['fit'][$slug] ?? 0) >= 70)); $key = wa_prod_sales_key($in);
        usort($in, fn($a, $b) => $key($b) <=> $key($a) ?: $a['id'] <=> $b['id']); $r = 0; foreach ($in as $i => $x) if ($x['id'] === $p['id']) { $r = $i + 1; break; }
        $parts[] = $r > 0 && $r <= 5 ? '#' . $r . ' for ' . $lab : (((int)($p['fit'][$slug] ?? 0)) >= 70 ? 'good for ' . $lab : '');
    } else { [$r, $lab] = wa_prod_overall_rank($all, $p['id']); if ($r > 0 && $r <= 5) $parts[] = '#' . $r . ' ' . $lab; }
    return implode(', ', array_slice(array_values(array_filter($parts)), 0, 3));
}
function wa_prod_similar_score(array $a, array $b): float
{
    $fa = array_keys(wa_prod_fam_of($a['notes'], (string)$a['scent'])); $fb = array_keys(wa_prod_fam_of($b['notes'], (string)$b['scent'])); $u = count(array_unique(array_merge($fa, $fb))); if ($u === 0) return 0;
    return count(array_intersect($fa, $fb)) / $u + ($a['gender'] === $b['gender'] ? 0.2 : 0) + count(array_intersect($a['seasons'], $b['seasons'])) * 0.05;
}
/** The best products for a request. Customer-safe by default (no real original name). $opt: kind (perfume|gel|candle|other|any), exclude [ids], staff (adds the real original), strict (unknown name → nothing), fill_similar. Rows: id, name, photo, notes, longevity, seasons, occasions, why, price_text (+ more). */
function wa_prod_pick(PDO $pdo, string $query, int $n = 3, array $opt = []): array
{
    if (!wa_prod_tables($pdo)) return [];
    $all = wa_prod_all($pdo); $kind = (string)($opt['kind'] ?? 'perfume'); $rows = array_values(array_filter($all, fn($p) => $kind === 'any' || $p['kind'] === $kind)); if (!$rows) return [];
    $parse = $opt['parse'] ?? wa_prod_parse($pdo, $query); $ex = array_flip(array_map('intval', (array)($opt['exclude'] ?? []))); $n = max(1, min(40, $n));
    $out = []; $named = [];
    if ($parse['words']) {   // the typed words that are not request words; also the whole typed text ("toy boy" is a name, not "boy" = men)
        $sets = [$parse['words']]; $stop = wa_prod_stop(); $full = array_values(array_filter(wa_prod_words($query), fn($w) => strlen($w) >= 2 && !isset($stop[$w]) && !preg_match('/^\d+$/', $w))); if ($full && $full !== $parse['words']) $sets[] = $full;
        foreach ($rows as $p) foreach ($sets as $ws) { $sc = wa_prod_name_score($ws, implode(' ', $ws), $p); if ($sc >= 60 && $sc > ($named[$p['id']] ?? 0)) $named[$p['id']] = $sc; }
        if (!$named && count($parse['words']) > 1) {   // nothing matched all the words: the one long word that matches strongly is the name ("aventus karo")
            foreach ($rows as $p) foreach ($parse['words'] as $w) if (strlen($w) >= 5) { $sc = wa_prod_name_score([$w], $w, $p); if ($sc >= 85 && $sc > ($named[$p['id']] ?? 0)) $named[$p['id']] = $sc; }
        }
    }
    if ($parse['words'] && !$named && !empty($opt['strict'])) return [];
    $by = []; foreach ($rows as $p) $by[$p['id']] = $p;
    $key = wa_prod_sales_key($rows); $need = $parse['occ'];
    $avg = function (array $p) use ($need) { if (!$need) return 0; $s = 0; foreach ($need as $k) $s += (int)($p['fit'][$k] ?? 0); return $s / count($need); };
    if ($named) {
        $ids = array_keys($named); usort($ids, fn($a, $b) => $named[$b] <=> $named[$a] ?: intdiv((int)$avg($by[$b]), 10) <=> intdiv((int)$avg($by[$a]), 10) ?: $key($by[$b]) <=> $key($by[$a]) ?: $a <=> $b);
        foreach ($ids as $id) if (!isset($ex[$id])) $out[] = $by[$id];
        if (count($out) < $n && !empty($opt['fill_similar']) && $out) {
            $first = $out[0]; $have = array_flip(array_column($out, 'id')); $rest = array_filter($rows, fn($p) => !isset($have[$p['id']]) && !isset($ex[$p['id']]));
            usort($rest, fn($a, $b) => wa_prod_similar_score($first, $b) <=> wa_prod_similar_score($first, $a) ?: $key($b) <=> $key($a) ?: $a['id'] <=> $b['id']); $out = array_merge($out, $rest);
        }
    } else {
        $c = array_values(array_filter($rows, fn($p) => !isset($ex[$p['id']])));
        if ($parse['who'] !== '') { $w = $parse['who']; $c2 = array_values(array_filter($c, fn($p) => $p['gender'] === $w || ($w !== 'unisex' && $p['gender'] === 'unisex') || $p['gender'] === '')); if ($c2) $c = $c2; }
        if ($need) {
            $min = function (array $p) use ($need) { $m = 100; foreach ($need as $k) $m = min($m, (int)($p['fit'][$k] ?? 0)); return $m; };
            $a = array_values(array_filter($c, fn($p) => $min($p) >= 60)); if (count($a) < $n) { $b = array_values(array_filter($c, fn($p) => $avg($p) >= 50)); if (count($b) > count($a)) $a = $b; }
            $c = $a ?: $c;
        }
        $key = wa_prod_sales_key($c ?: $rows);
        usort($c, fn($a, $b) => intdiv((int)$avg($b), 10) <=> intdiv((int)$avg($a), 10) ?: $key($b) <=> $key($a) ?: $b['rating'] <=> $a['rating'] ?: $a['id'] <=> $b['id']);
        $out = $c;
    }
    $out = array_slice($out, 0, $n); $res = [];
    foreach ($out as $p) $res[] = wa_prod_item($p, $all, $parse, !empty($opt['staff']));
    return $res;
}
/** One result row for the app. */
function wa_prod_item(array $p, array $all, array $parse, bool $staff): array
{
    $photos = array_map('wa_prod_thumb', $p['photos']); $labelOf = wa_prod_labels();
    $why = wa_prod_why($p, $all, $parse['occ'] ?? [], $parse['labels'] ?? []); [$r, $lab] = wa_prod_overall_rank($all, $p['id']);
    $it = ['id' => $p['id'], 'name' => $p['name'], 'photo' => $p['photos'][0] ?? '', 'thumb' => $photos[0] ?? '', 'photos' => $p['photos'], 'notes' => implode(', ', array_slice($p['notes'], 0, 6)), 'longevity' => $p['longevity'], 'seasons' => $p['seasons'],
        'occasions' => $p['tags'], 'occasion_labels' => array_values(array_filter(array_map(fn($s) => $labelOf[$s] ?? '', array_slice($p['tags'], 0, 4)))), 'why' => $why, 'price_text' => $p['price_text'], 'gender' => $p['gender'],
        'rank' => $r > 0 && $r <= 10 ? '#' . $r . ' ' . $lab : '', 'units' => $p['units_sold'], 'rating' => $p['rating']];
    if ($staff) { $it['original'] = $p['original_real'] !== '' ? $p['original_real'] : $p['original_site']; $it['original_site'] = $p['original_site']; $it['original_real'] = $p['original_real']; $it['url'] = $p['url']; $it['notes_top'] = $p['notes_top']; $it['notes_mid'] = $p['notes_mid']; $it['notes_base'] = $p['notes_base']; }
    return $it;
}
function wa_prod_pdo(): PDO { return $GLOBALS['__wp_pdo'] ?? $GLOBALS['pdo']; }   // set by wa_prod_tables(); falls back to the app's connection
/** slug => label of all request types (cached for the request). */
function wa_prod_labels(bool $fresh = false): array
{
    static $c = null; if ($c !== null && !$fresh) return $c; $c = []; foreach (wa_prod_occ_rows(wa_prod_pdo()) as $o) $c[(string)$o['slug']] = (string)$o['label']; return $c;
}
/** Small version of a Shopify CDN image for lists. */
function wa_prod_thumb(string $u, int $w = 240): string { return preg_match('#^https://cdn\.shopify\.com/#i', $u) ? $u . (strpos($u, '?') === false ? '?' : '&') . 'width=' . $w : $u; }

// ------------------------------------------------------------------------------------------------------------------ what the customer wrote
/** Does the customer's last message ask to see / suggest a perfume, an occasion, notes, longevity or a photo? Rules only (fast, no AI). */
function wa_prod_intent(PDO $pdo, string $text): array
{
    $o = ['yes' => false, 'q' => '', 'asks_long' => false, 'parse' => null, 'named' => false, 'again' => false];
    $f = wa_prod_fold($text, false); if ($f === '' || strlen($f) > 400 || !wa_prod_tables($pdo)) return $o;
    $cue = (bool)preg_match('/\b(dikhao|dikha|dikhana|dikhaiye|bhejo|bhej|bhejiye|send|share|show|photo|photos|pic|pics|image|images|picture|suggest|suggestion|recommend\w*|batao|bata|btao|options?|collection|catalog\w*|konsa|kaun sa|kaunsa|which|top|list)\b/', $f);
    $perf = (bool)preg_match('/\b(perfumes?|attars?|fragrances?|scents?|deo|khushbu|mist|parfum)\b/', $f);
    $long = (bool)preg_match('/\b(kitni der|kitna der|kitne der|how long|lasting|last kar\w*|tikta|tikega|tike|hours?|hrs?|stay|chalta|chalega|longevity)\b/', $f);
    $bad = (bool)preg_match('/\b(order|orders|tracking|track|awb|delivery|deliver|delivered|payment|paid|pay|refund|return|cancel|complaint|damage\w*|wrong|not received|nahi mila|kab aayega|invoice|bill|cod|cash on delivery|address|pincode|courier|dispatch\w*|shipped|shipping)\b/', $f);
    $price = (bool)preg_match('/\b(price|rate|cost|kitne ka|kitna|kimat|mrp|discount|offer)\b/', $f);
    $storeQ = (bool)preg_match('/\b(store|stores|shop|shops|dukaan|dukan|showroom|outlet|open|opens|opening|close|closes|closing|closed|khul\w*|band|timings?|working|business|office|baje|visit|location|map)\b/', $f);   // "what are your store hours" is not "how long does it last"
    if ($storeQ && !$cue && !$perf) $long = false;
    if (preg_match('/^(hi+|hello+|hey+|hlo|namaste|namaskar|good (morning|evening|afternoon|night)|ok|okay|k|thanks|thank you|thx|thankyou|yes|no|haan|ha|nahi|ji|done)\W*$/', $f)) return $o;
    $parse = wa_prod_parse($pdo, $text); $o['parse'] = $parse; $o['asks_long'] = $long;
    $bestN = 0.0; if ($parse['words']) { $raw = implode(' ', $parse['words']); foreach (wa_prod_all($pdo) as $p) $bestN = max($bestN, wa_prod_name_score($parse['words'], $raw, $p)); }
    $named = $bestN >= 70; $o['named'] = $named; $maxw = 0; foreach ($parse['words'] as $w) $maxw = max($maxw, strlen($w));
    $occ = $parse['occ'] || $parse['who'] !== ''; $short = count(explode(' ', $f)) <= 7; $yes = false;
    if ($named && ($cue || $long || $perf || ($short && $bestN >= 90 && $maxw >= 5))) $yes = true;
    elseif ($occ && ($cue || $perf)) $yes = true;
    elseif ($cue && $perf) $yes = true;
    elseif ($long && $perf) $yes = true;
    elseif ($cue && $short && !$parse['words'] && preg_match('/\b(photo|photos|pic|pics|image|picture|dikhao|dikha|bhejo|bhej)\b/', $f)) { $yes = true; $o['again'] = true; }
    elseif ($long && !$named && !$occ && !$perf && !$cue) { $yes = true; $o['again'] = true; }   // "kitni der chalta hai?" about the last product
    if ($bad && !$cue && !$named) $yes = false;
    if ($storeQ && !$cue && !$named && !$perf) $yes = false;
    if ($price && !$cue && !$occ && !$long) $yes = false;
    $o['yes'] = $yes; $o['q'] = trim($text); return $o;
}
function wa_prod_recent(PDO $pdo, string $phone, int $hours = 24): array
{
    $q = $pdo->prepare("SELECT prod_id, photo_idx FROM wa_prod_seen WHERE phone = ? AND at >= ?"); $q->execute([$phone, date('Y-m-d H:i:s', time() - $hours * 3600)]); $o = [];
    foreach ($q->fetchAll() as $r) $o[(int)$r['prod_id']][] = (int)$r['photo_idx']; return $o;
}
function wa_prod_seen_mark(PDO $pdo, string $phone, int $prodId, int $idx, string $wamid): void
{
    $pdo->prepare("INSERT INTO wa_prod_seen (phone, prod_id, photo_idx, wamid, at) VALUES (?, ?, ?, ?, ?)")->execute([$phone, $prodId, $idx, mb_substr($wamid, 0, 80), date('Y-m-d H:i:s')]);
    if (mt_rand(1, 50) === 1) $pdo->prepare("DELETE FROM wa_prod_seen WHERE at < ?")->execute([date('Y-m-d H:i:s', time() - 60 * 86400)]);
}
/** The perfume the customer last asked about (our name), else the last one we showed, else ''. For campaign variables. */
function wa_prod_last_asked(PDO $pdo, string $phone): string
{
    if (!wa_prod_tables($pdo)) return ''; $key = function_exists('wa_key') ? wa_key($phone) : substr(preg_replace('/\D+/', '', $phone), -10); if ($key === '') return '';
    $rows = array_values(array_filter(wa_prod_all($pdo), fn($p) => $p['kind'] === 'perfume')); if (!$rows) return '';
    $df = []; $toks = []; foreach ($rows as $p) { $t = []; foreach (explode(' ', str_replace(' | ', ' ', (string)($p['search_txt'] ?: wa_prod_search_txt($p)))) as $w) if ($w !== '') $t[$w] = 1; $toks[$p['id']] = $t; foreach ($t as $w => $_) $df[$w] = ($df[$w] ?? 0) + 1; }
    $generic = array_flip(['man', 'men', 'homme', 'pour', 'sport', 'love', 'blue', 'black', 'red', 'white', 'one', 'new', 'pure', 'the', 'and', 'for', 'her', 'him', 'woman', 'women', 'intense', 'elixir', 'night', 'day']) + wa_prod_stop();
    $q = $pdo->prepare("SELECT body FROM wa_messages WHERE phone = ? AND dir = 'in' AND mtype = 'text' ORDER BY id DESC LIMIT 12"); $q->execute([$key]);
    foreach ($q->fetchAll() as $m) {
        $words = array_values(array_filter(wa_prod_words((string)$m['body']), fn($w) => strlen($w) >= 3 && !isset($generic[$w]))); if (!$words) continue; $best = null; $bs = 0;
        foreach ($rows as $p) {
            $hit = 0; foreach ($words as $w) { foreach ($toks[$p['id']] as $t => $_) {
                if (isset($generic[$t]) || ($df[$t] ?? 9) > 2) continue;
                if ($t === $w || (strlen($w) >= 4 && strncmp($t, $w, strlen($w)) === 0) || (strlen($w) >= 5 && abs(strlen($t) - strlen($w)) <= 2 && levenshtein($w, $t) <= 1)) { $hit++; break; }
            } }
            if ($hit > $bs || ($hit === $bs && $hit > 0 && $best && $p['units_sold'] > $best['units_sold'])) { $bs = $hit; $best = $p; }
        }
        if ($best && $bs > 0) return (string)$best['name'];
    }
    $s = $pdo->prepare("SELECT prod_id FROM wa_prod_seen WHERE phone = ? AND prod_id > 0 AND at >= ? ORDER BY id DESC LIMIT 1"); $s->execute([$key, date('Y-m-d H:i:s', time() - 7 * 86400)]); $id = (int)$s->fetchColumn();
    if ($id > 0) foreach ($rows as $p) if ($p['id'] === $id) return (string)$p['name'];
    return '';
}

// ------------------------------------------------------------------------------------------------------------------ caption (customer-facing: website spelling only)
function wa_prod_caption(array $p, string $mode = 'photo', array $opt = []): string
{
    $p = wa_prod_dec($p); $L = ['*' . $p['name'] . '*'];
    if ($mode === 'why') {
        $why = trim((string)($opt['why'] ?? '')); if ($why !== '') $L[] = $why; if ($p['price_text'] !== '') $L[] = $p['price_text'];
    } elseif ($mode === 'photo_notes' || $mode === 'all') {
        if ($p['original_site'] !== '') $L[] = 'Inspired by: ' . $p['original_site'];
        if ($p['notes']) $L[] = 'Notes: ' . implode(', ', array_slice($p['notes'], 0, 8));
        if ($p['longevity'] !== '') $L[] = 'Lasts: ' . $p['longevity'];
        if ($mode === 'all') {
            $labelOf = wa_prod_labels(); $best = [];
            foreach ($p['tags'] as $s) { if (in_array($s, ['best-sellers', 'recommended', 'compliments', 'celebrity', 'budget', 'gift'], true)) continue; if (isset($labelOf[$s])) $best[] = $labelOf[$s]; if (count($best) >= 3) break; }
            if ($best) $L[] = 'Best for: ' . implode(', ', $best); if ($p['price_text'] !== '') $L[] = 'Price: ' . $p['price_text'];
        }
    }
    $t = implode("\n", $L); return mb_strlen($t) > 1024 ? mb_substr($t, 0, 1021) . '...' : $t;
}

// ------------------------------------------------------------------------------------------------------------------ photos from the Photos tab (library)
/** The Photos-tab sections the bot may attach, with the customer-facing slot names. registration (locked), reviews (staff only), quality, bulk and other are NEVER attached by themselves. */
function wa_prod_lib_defs(): array
{
    return [
        // no 'pricelist' here on purpose: the price list picture is drawn by the system from the Price chart (price_charts.php), never taken from an uploaded photo
        'packaging' => ['Packaging', ['box_closed' => 'Our box', 'box_open' => 'Inside the box', 'in_foam' => 'Bottle inside the box', 'outer' => 'Outer parcel']],
        'sizes'     => ['Sizes', ['together' => '30 / 50 / 100 ml together', 'hand' => 'Bottle in hand']],
        'offer'     => ['Offers', ['offer' => 'Current offer', 'combo' => 'Combo offer']],
        'gift'      => ['Gift sets', ['gift_box' => 'Gift box', 'hamper' => 'Hamper', 'byob' => 'Build-Your-Own-Box']],
        'candle'    => ['Candles', ['group' => 'Our candles', 'single' => 'Candle', 'lit' => 'Lit candle']],
        'store'     => ['Store', ['outside' => 'Our store', 'inside' => 'Inside the store', 'shelves' => 'Our shelves']],
        'delivery'  => ['Delivery proof', ['handover' => 'Parcel handed to the courier', 'delivered' => 'Delivered parcel']],
    ];
}
/** What does the customer's message ask for from the Photos tab? Rules only. Returns [['section'=>, 'slots'=>[wanted slots, best first], 'label'=>], …] (max 3). Never registration / reviews. */
function wa_prod_lib_intent(PDO $pdo, string $text): array
{
    $f = wa_prod_fold($text, false); if ($f === '' || strlen($f) > 400) return [];
    $has = fn(string $re): bool => (bool)preg_match($re, $f);
    if ($has('/^(hi+|hello+|hey+|hlo|namaste|namaskar|good (morning|evening|afternoon|night)|ok|okay|k|thanks|thank you|thx|thankyou|yes|no|haan|ha|nahi|ji|done)\W*$/')) return [];
    $photo = $has('/\b(photo|photos|pic|pics|image|images|picture|pictures|dikhao|dikha|dikhana|dikhaiye|dikhaye|show|dekh\w*)\b/');
    $explicit = $has('/\b(photo|photos|pic|pics|image|images|picture|pictures)\b/');
    $send = $has('/\b(bhejo|bhej|bhejiye|send|share)\b/');
    $out = [];
    $gift = $has('/\b(gift|gifts|gifting|hamper|hampers|giftset|gift set|box set|byob|build your own|combo box)\b/');
    $delivery = $has('/\b(delivery|delivered|dispatch\w*|shipped|handover|hand over|handed|courier|kab gaya|gaya|bheja)\b/') && $has('/\b(proof|photo|photos|pic|pics|image|picture|sabut|saboot|dikhao|show)\b/');
    if ($delivery) $out[] = ['section' => 'delivery', 'slots' => $has('/\b(delivered|received|customer)\b/') ? ['delivered', 'handover'] : ['handover', 'delivered']];
    $packWord = $has('/\b(box|boxes|packing|packaging|packed|parcel|pack|unboxing|dabba|dabbe)\b/');
    if (!$delivery && $packWord && (!$gift || $has('/\b(packing|packaging|packed|unboxing)\b/')) && ($photo || $send || $has('/\b(packing|packaging|unboxing|kaisa|kaisi|kaise|look|dikh\w*)\b/'))) $out[] = ['section' => 'packaging', 'slots' => $has('/\b(open|inside|andar|kholke|unboxing)\b/') ? ['box_open', 'box_closed'] : ['box_closed', 'box_open']];
    $sizeWord = $has('/\b(sizes?|kitna bada|kitni badi|kitne bade|bottle size)\b/'); $mlWord = $has('/\b\d+ ?ml\b/');
    if ($has('/\b(kitna bada|kitni badi|kitne bade)\b/') || ($sizeWord && ($photo || $send || $has('/\b(kya|konsa|kaun|which|available|kitne|options|all|sab)\b/'))) || ($mlWord && $explicit)) $out[] = ['section' => 'sizes', 'slots' => $has('/\b(hand|haath|haat)\b/') ? ['hand', 'together'] : ['together', 'hand']];
    if ($has('/\b(offers?|combos?|deals?|schemes?|sale|promo|promotion)\b/') && !($gift && $has('/\bcombo box\b/'))) $out[] = ['section' => 'offer', 'slots' => $has('/\bcombos?\b/') ? ['combo', 'offer'] : ['offer', 'combo']];
    if ($gift) $out[] = ['section' => 'gift', 'slots' => $has('/\b(byob|build your own)\b/') ? ['byob', 'gift_box'] : ($has('/\b(hamper|hampers|set|giftset)\b/') ? ['hamper', 'gift_box'] : ['gift_box', 'hamper'])];
    if ($has('/\b(candles?|mombatti|mombattiyan)\b/')) $out[] = ['section' => 'candle', 'slots' => ['group', 'single']];
    // store photos need a photo / show cue; "what are your store hours" is not one
    $storeWord = $has('/\b(store|stores|shop|showroom|dukaan|dukan|outlet|vesu)\b/'); $timing = $has('/\b(timings?|time|hours?|open|opens|opening|close|closes|closing|closed|khul\w*|band|baje|kab|address|location|map|where|kahan|direction\w*|route)\b/');
    if ($storeWord && ($photo || $has('/\b(inside|outside)\b/')) && !($timing && !$explicit)) $out[] = ['section' => 'store', 'slots' => $has('/\b(inside|andar|counter)\b/') ? ['inside', 'shelves', 'outside'] : ($has('/\b(shelf|shelves)\b/') ? ['shelves', 'inside', 'outside'] : ['outside', 'inside'])];
    $defs = wa_prod_lib_defs(); $res = [];
    foreach ($out as $x) { if (!isset($defs[$x['section']])) continue; $x['label'] = $defs[$x['section']][0]; $res[] = $x; }
    return array_slice($res, 0, 3);
}
function wa_prod_lib_dir(): string { return function_exists('wa_lib_dir') ? wa_lib_dir() : __DIR__ . '/wa_files'; }
/** A small picture (220 px) as a data: link; the same cached file the Photos tab uses. */
function wa_prod_lib_thumb(string $path): string
{
    $f = wa_prod_lib_dir() . '/' . basename($path); $t = $f . '.t.jpg'; $b = is_file($t) ? (string)@file_get_contents($t) : '';
    if ($b === '' && is_file($f) && function_exists('imagecreatefromstring')) {
        $raw = @file_get_contents($f); $im = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($im) { $w = imagesx($im); $h = imagesy($im); $k = min(1, 220 / max($w, $h)); $d = imagecreatetruecolor(max(1, (int)round($w * $k)), max(1, (int)round($h * $k))); imagecopyresampled($d, $im, 0, 0, 0, 0, imagesx($d), imagesy($d), $w, $h);
            ob_start(); imagejpeg($d, null, 72); $b = (string)ob_get_clean(); imagedestroy($im); imagedestroy($d); if ($b !== '') @file_put_contents($t, $b); }
    }
    return $b !== '' ? 'data:image/jpeg;base64,' . base64_encode($b) : '';
}
function wa_prod_lib_item(array $r, bool $thumb = true): array
{
    $defs = wa_prod_lib_defs(); $sec = (string)$r['section']; $slot = (string)$r['slot']; $title = $defs[$sec][0] ?? 'Photo'; $label = (string)($defs[$sec][1][$slot] ?? $title);
    return ['id' => 'lib:' . (int)$r['id'], 'kind' => 'lib', 'ph_id' => (int)$r['id'], 'lib_id' => (int)$r['lib_id'], 'section' => $sec, 'slot' => $slot, 'name' => $label, 'label' => $label, 'caption' => $label,
        'source' => 'Photos tab: ' . $title, 'why' => 'Photos tab: ' . $title, 'thumb' => $thumb ? wa_prod_lib_thumb((string)$r['path']) : '', 'photo' => ''];
}
/** Good photos of the allowed sections, newest first: rows of ph_photos joined to the media library. A missing table (Photos tab never used) = no photos. */
function wa_prod_lib_rows(PDO $pdo, array $sections = [], array $phIds = []): array
{
    $allowed = array_keys(wa_prod_lib_defs()); $sections = $sections ? array_values(array_intersect($sections, $allowed)) : $allowed; if (!$sections && !$phIds) return [];
    try {
        $w = "p.status = 'ok' AND p.hide = 0 AND p.lib_id > 0"; $par = [];
        if ($phIds) { $w .= " AND p.id IN (" . implode(',', array_fill(0, count($phIds), '?')) . ")"; $par = array_values($phIds); }
        $w .= " AND p.section IN (" . implode(',', array_fill(0, count($allowed), '?')) . ")"; $par = array_merge($par, $allowed);
        if (!$phIds) { $w .= " AND p.section IN (" . implode(',', array_fill(0, count($sections), '?')) . ")"; $par = array_merge($par, $sections); }
        $q = $pdo->prepare("SELECT p.id, p.section, p.slot, p.tag, p.label, p.path, p.lib_id FROM ph_photos p JOIN wa_media_lib l ON l.id = p.lib_id WHERE $w ORDER BY p.id DESC"); $q->execute($par); return $q->fetchAll();
    } catch (PDOException $e) { return []; }
}
/** Photos for the intents: per section the newest photo of each wanted slot that this customer did not get in the last 24 h (max 2 per section, $max in all). */
function wa_prod_lib_pick(PDO $pdo, string $phone, array $intents, int $max, array $opt = []): array
{
    if (!$intents || $max < 1) return []; $recent = $phone !== '' && empty($opt['ignore_recent']) ? wa_prod_recent($pdo, $phone, 24) : []; $cands = [];
    foreach ($intents as $k => $in) {
        $rows = wa_prod_lib_rows($pdo, [(string)$in['section']]); if (!$rows) continue; $bySlot = []; foreach ($rows as $r) if (!isset($recent[-(int)$r['id']])) $bySlot[(string)$r['slot']][] = $r;
        $haveWanted = false; foreach ($rows as $r) if (in_array((string)$r['slot'], (array)$in['slots'], true)) { $haveWanted = true; break; }   // no photo of the asked slot at all -> any photo of the section; otherwise only the asked slots (a retail customer is not sent the bulk list)
        $order = $haveWanted ? array_values((array)$in['slots']) : array_keys($bySlot); $perSec = count($in['slots']) > 1 && in_array($in['section'], ['packaging', 'store', 'gift', 'candle'], true) ? 2 : 1;
        foreach ($order as $s) if (!empty($bySlot[$s]) && count($cands[$k] ?? []) < $perSec) $cands[$k][] = $bySlot[$s][0];
    }
    $out = []; $used = [];   // one photo of every asked section first, then the second photo of a section
    foreach ([0, 1] as $round) foreach ($cands as $list) {
        if (count($out) >= $max) break 2; if (!isset($list[$round]) || isset($used[$list[$round]['id']])) continue;
        $used[$list[$round]['id']] = 1; $out[] = wa_prod_lib_item($list[$round]);
    }
    return $out;
}
/** [phId => item] for photos the bot / staff may send (good photo, allowed section). */
function wa_prod_lib_get(PDO $pdo, array $phIds): array
{
    $phIds = array_values(array_unique(array_filter(array_map('intval', $phIds), fn($x) => $x > 0))); if (!$phIds) return []; $o = [];
    foreach (wa_prod_lib_rows($pdo, [], $phIds) as $r) $o[(int)$r['id']] = $r; return $o;
}
/** Sends Photos-tab pictures through the media library (upload once, then by media id). Same gates and the same chat rows as a person sending from the library. */
function wa_prod_send_lib(PDO $pdo, array $c, array $phIds, string $who, int $empId, array $opt = []): array
{
    $res = ['sent' => [], 'skipped' => [], 'errors' => []];
    if (!function_exists('wa_lib_media_id') || !function_exists('wa_send_media') || !function_exists('wa_lib_row')) { foreach ($phIds as $id) $res['errors'][] = ['id' => 'lib:' . $id, 'name' => 'Photo', 'error' => 'The media library is not available.']; return $res; }
    $rows = wa_prod_lib_get($pdo, $phIds); $phone = (string)$c['phone']; $recent = wa_prod_recent($pdo, $phone, 24); $force = !empty($opt['force']); $i = 0; $q2 = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q2->execute([(int)$c['last_number_id']]); $pid = (string)$q2->fetchColumn(); if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID');
    foreach ($phIds as $id) {
        $key = 'lib:' . $id; $r = $rows[$id] ?? null; $it = $r ? wa_prod_lib_item($r, false) : ['name' => 'Photo'];
        if (!$r) { $res['skipped'][] = ['id' => $key, 'name' => 'Photo', 'why' => 'This photo is not available any more (or it is a staff-only photo).']; continue; }
        if (isset($recent[-$id]) && !$force && ($who === 'bot' || ($opt['mode'] ?? '') === 'why')) { $res['skipped'][] = ['id' => $key, 'name' => $it['name'], 'why' => 'Already sent in the last 24 hours.']; continue; }
        if (isset($recent[-$id]) && !$force) { $res['skipped'][] = ['id' => $key, 'name' => $it['name'], 'why' => 'Already sent in the last 24 hours.', 'recent' => true]; continue; }
        $lib = wa_lib_row($pdo, (int)$r['lib_id']); if (!$lib) { $res['skipped'][] = ['id' => $key, 'name' => $it['name'], 'why' => 'The saved file is missing in the media library.']; continue; }
        if ($pid === '') { $res['errors'][] = ['id' => $key, 'name' => $it['name'], 'error' => 'WA_PHONE_ID is missing in config.php.']; continue; }
        [$mid, $e] = wa_lib_media_id($pdo, $lib, $pid); $ok = false; $out = $e;
        if ($mid !== '') {
            $keep = [(string)$c['state'], (string)$c['status'], $c['closed_at'] ?? null];
            [$ok, $out] = wa_send_media($pdo, $c, (string)$lib['kind'], $mid, (string)$lib['mime'], (string)$it['caption'], (string)$lib['filename'], $empId);
            if (!$ok && preg_match('/media|expired|not found|131053|131052/i', (string)$out)) { [$mid, $e] = wa_lib_media_id($pdo, $lib, $pid, true); if ($mid !== '') [$ok, $out] = wa_send_media($pdo, $c, (string)$lib['kind'], $mid, (string)$lib['mime'], (string)$it['caption'], (string)$lib['filename'], $empId); }
            if ($ok && $who === 'bot') {   // wa_send_media is for people; a bot photo must not hand the chat over
                $pdo->prepare("UPDATE wa_messages SET who = 'bot', emp_id = 0 WHERE wamid = ?")->execute([$out['wamid']]);
                $pdo->prepare("UPDATE wa_contacts SET state = ?, status = ?, closed_at = ? WHERE phone = ?")->execute([$keep[0], $keep[1], $keep[2], $phone]);
            }
        }
        if ($ok) { wa_prod_seen_mark($pdo, $phone, -$id, 0, (string)$out['wamid']); $recent[-$id] = [0]; $res['sent'][] = ['id' => $key, 'kind' => 'lib', 'name' => $it['name'], 'wamid' => (string)$out['wamid'], 'via' => 'library']; }
        else $res['errors'][] = ['id' => $key, 'name' => $it['name'], 'error' => (string)$out];
        if (empty($opt['nosleep']) && ++$i < count($phIds)) usleep(400000);
    }
    return $res;
}
/** Product ids (ints) and Photos-tab photos ('lib:<id>') in ONE send, 3 in all for a prepared reply, 6 for a person. Gate errors come back as ['ok'=>false,'error'=>…]. */
function wa_prod_send_items(PDO $pdo, array $c, array $items, string $mode, string $who, int $empId, array $opt = []): array
{
    if ((int)$c['optout'] === 1) return ['ok' => false, 'error' => 'This customer opted out — do not message them.'];
    if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return ['ok' => false, 'error' => 'The 24-hour reply window is closed. Only an approved pre-made message can be sent now.'];
    if (!in_array($mode, ['photo', 'photo_notes', 'all', 'why'], true)) $mode = 'photo';
    $cap = $mode === 'why' ? 3 : 6; $prod = []; $lib = []; $order = [];
    foreach ($items as $x) {
        if (is_string($x) && preg_match('/^lib:(\d+)$/', $x, $m)) { $n = (int)$m[1]; if ($n > 0 && !isset($lib[$n])) { $lib[$n] = 1; $order[] = ['l', $n]; } }
        elseif (is_numeric($x) && (int)$x > 0 && !isset($prod[(int)$x])) { $prod[(int)$x] = 1; $order[] = ['p', (int)$x]; }
    }
    foreach ((array)($opt['lib'] ?? []) as $n) { $n = (int)$n; if ($n > 0 && !isset($lib[$n])) { $lib[$n] = 1; $order[] = ['l', $n]; } }
    $order = array_slice($order, 0, $cap); $p = []; $l = []; foreach ($order as $o) { if ($o[0] === 'p') $p[] = $o[1]; else $l[] = $o[1]; }
    if (!$p && !$l) return ['ok' => false, 'error' => 'Pick a perfume or a photo first.'];
    if (!$l) return wa_prod_send_many($pdo, $c, $p, $mode, $who, $empId, $opt + ['cap' => $cap]);   // products only: exactly the old behaviour
    $res = ['ok' => true, 'sent' => [], 'skipped' => [], 'errors' => []];
    if ($p) { $r = wa_prod_send_many($pdo, $c, $p, $mode, $who, $empId, $opt + ['cap' => $cap]); foreach (['sent', 'skipped', 'errors'] as $k) $res[$k] = array_merge($res[$k], $r[$k] ?? []); if (empty($r['sent']) && empty($r['errors']) && empty($r['skipped']) && !empty($r['error'])) $res['errors'][] = ['id' => 0, 'name' => '', 'error' => (string)$r['error']]; if (empty($opt['nosleep'])) usleep(400000); }
    $r = wa_prod_send_lib($pdo, $c, $l, $who, $empId, $opt + ['mode' => $mode]); foreach (['sent', 'skipped', 'errors'] as $k) $res[$k] = array_merge($res[$k], $r[$k] ?? []);
    if (!$res['sent'] && $res['errors']) { $res['ok'] = false; $res['error'] = $res['errors'][0]['error']; }
    return $res;
}

// ------------------------------------------------------------------------------------------------------------------ sending photos
/** Shopify CDN: a 1000 px JPEG is plenty for WhatsApp and always under Meta's 5 MB limit. */
function wa_prod_img_url(string $u): string
{
    if (!preg_match('#^https://cdn\.shopify\.com/#i', $u)) return $u;
    $u = preg_replace('/([?&])(width|height|format)=[^&]*&?/i', '$1', $u) ?? $u; $u = rtrim($u, '?&');
    return $u . (strpos($u, '?') === false ? '?' : '&') . 'width=1000&format=jpg';
}
/** A photo by LINK (Meta fetches it from our CDN — nothing to upload). From the number the customer last used; 24-hour window and opt-out like every other send. [ok, ['wamid'=>…]|error] */
function wa_send_image_link(PDO $pdo, array $c, string $url, string $caption, string $who, int $empId, int $prodId = 0): array
{
    if ((int)$c['optout'] === 1) return [false, 'This customer opted out — do not message them.'];
    if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return [false, 'The 24-hour reply window is closed. Only an approved template can be sent now.'];
    if (!preg_match('#^https://#i', $url)) return [false, 'The photo link is not valid.'];
    $q = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q->execute([(int)$c['last_number_id']]); $pid = (string)$q->fetchColumn();
    if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID'); if ($pid === '') return [false, 'WA_PHONE_ID is missing in config.php.'];
    $obj = ['link' => $url]; if ($caption !== '') $obj['caption'] = mb_substr($caption, 0, 1024);
    [$code, $d] = wa_api('POST', '/' . $pid . '/messages', ['messaging_product' => 'whatsapp', 'to' => (string)($c['wa_id'] ?: '91' . $c['phone']), 'type' => 'image', 'image' => $obj]);
    $wamid = (string)($d['messages'][0]['id'] ?? '');
    if ($code < 200 || $code >= 300 || $wamid === '') { $e = wa_err($d); wa_log($pdo, 'send', false, $c['phone'] . ' · photo link · ' . $e); return [false, $e]; }
    $now = date('Y-m-d H:i:s'); $mid = $prodId > 0 ? 'prod:' . $prodId : 'link:' . substr(md5($url), 0, 12);
    $pdo->prepare("INSERT INTO wa_messages (wamid, phone, number_id, dir, mtype, body, media_id, media_mime, status, who, emp_id, ts) VALUES (?, ?, ?, 'out', 'image', ?, ?, 'image/jpeg', 'sent', ?, ?, ?)")
        ->execute([$wamid, $c['phone'], (int)$c['last_number_id'], '📷 Photo' . ($caption !== '' ? ': ' . mb_substr($caption, 0, 300) : ''), $mid, $who === 'bot' ? 'bot' : 'emp', $who === 'bot' ? 0 : $empId, $now]);
    if ($who === 'bot') $pdo->prepare("UPDATE wa_contacts SET last_out_at = ? WHERE phone = ?")->execute([$now, $c['phone']]);
    else $pdo->prepare("UPDATE wa_contacts SET last_out_at = ?, state = 'human', status = 'open', closed_at = NULL WHERE phone = ?")->execute([$now, $c['phone']]);   // a person replied → the bot stays quiet, a closed chat re-opens
    return [true, ['wamid' => $wamid, 'media_id' => $mid]];
}
/** Fallback when Meta cannot fetch the link: download the photo, keep it in the media library, upload it to Meta and send it. [ok, ['wamid'=>…]|error] */
function wa_prod_send_via_library(PDO $pdo, array $c, string $url, array $p, string $caption, string $who, int $empId): array
{
    if (!function_exists('wa_lib_media_id') || !function_exists('wa_send_media') || !function_exists('wa_lib_dir')) return [false, 'The media library is not available.'];
    [$code, , $raw] = wa_http('GET', $url, ['User-Agent: Mozilla/5.0 (HRMS)'], null, true);
    $len = strlen((string)$raw); if ($code !== 200 || $len < 500 || $len > 5 * 1048576) return [false, 'Could not download the photo (HTTP ' . $code . ').'];
    $ext = ''; $mime = ''; if (strncmp($raw, "\xFF\xD8\xFF", 3) === 0) { $ext = 'jpg'; $mime = 'image/jpeg'; } elseif (strncmp($raw, "\x89PNG", 4) === 0) { $ext = 'png'; $mime = 'image/png'; } elseif (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP') { $ext = 'webp'; $mime = 'image/webp'; }
    if ($ext === '') return [false, 'The downloaded file is not a photo.'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_media_lib (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(80) NOT NULL, kind VARCHAR(10) NOT NULL, mime VARCHAR(60) NOT NULL DEFAULT '', filename VARCHAR(120) NOT NULL DEFAULT '',
        size INT NOT NULL DEFAULT 0, path VARCHAR(120) NOT NULL DEFAULT '', mids TEXT NULL, created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $fn = 'prod_' . substr(md5(preg_replace('/\?.*$/', '', $url) . $len), 0, 12) . '.' . $ext; $path = wa_lib_dir() . '/' . $fn;
    $q = $pdo->prepare("SELECT * FROM wa_media_lib WHERE filename = ? LIMIT 1"); $q->execute([$fn]); $row = $q->fetch();
    if (!$row || !is_file($path)) {
        if (@file_put_contents($path, $raw) === false) return [false, 'Could not save the photo on the server.'];
        if (!$row) { $pdo->prepare("INSERT INTO wa_media_lib (title, kind, mime, filename, size, path, mids, created_by, created_at) VALUES (?, 'image', ?, ?, ?, ?, '{}', 0, ?)")->execute([mb_substr('Product: ' . $p['name'], 0, 80), $mime, $fn, $len, $fn, date('Y-m-d H:i:s')]); $q->execute([$fn]); $row = $q->fetch(); }
    }
    if (!$row) return [false, 'Could not save the photo.'];
    $q2 = $pdo->prepare("SELECT phone_id FROM wa_numbers WHERE id = ?"); $q2->execute([(int)$c['last_number_id']]); $pid = (string)$q2->fetchColumn(); if ($pid === '') $pid = wa_cfg_val('WA_PHONE_ID');
    [$mid, $e] = wa_lib_media_id($pdo, $row, $pid); if ($mid === '') return [false, $e];
    $keep = [(string)$c['state'], (string)$c['status'], $c['closed_at'] ?? null];
    [$ok, $res] = wa_send_media($pdo, $c, 'image', $mid, $mime, $caption, $fn, $empId);
    if ($ok && $who === 'bot') {   // wa_send_media is for people; a bot photo must not hand the chat over
        $pdo->prepare("UPDATE wa_messages SET who = 'bot', emp_id = 0 WHERE wamid = ?")->execute([$res['wamid']]);
        $pdo->prepare("UPDATE wa_contacts SET state = ?, status = ?, closed_at = ? WHERE phone = ?")->execute([$keep[0], $keep[1], $keep[2], $c['phone']]);
    }
    return $ok ? [true, ['wamid' => $res['wamid'], 'media_id' => $mid]] : [false, (string)$res];
}
/** Sends up to 6 products (3 for a prepared reply) one by one. Photo = first one not already sent in the last 24 h. Gate errors come back as ['ok'=>false,'error'=>…]. */
function wa_prod_send_many(PDO $pdo, array $c, array $ids, string $mode, string $who, int $empId, array $opt = []): array
{
    $res = ['ok' => true, 'sent' => [], 'skipped' => [], 'errors' => []];
    if ((int)$c['optout'] === 1) return ['ok' => false, 'error' => 'This customer opted out — do not message them.'];
    if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return ['ok' => false, 'error' => 'The 24-hour reply window is closed. Only an approved pre-made message can be sent now.'];
    if (!in_array($mode, ['photo', 'photo_notes', 'all', 'why'], true)) $mode = 'photo';
    $cap = (int)($opt['cap'] ?? ($mode === 'why' ? 3 : 6)); $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, $cap); if (!$ids) return ['ok' => false, 'error' => 'Pick a perfume first.'];
    $rows = wa_prod_get($pdo, $ids); if (!$rows) return ['ok' => false, 'error' => 'Perfume not found. Refresh the list.'];
    $phone = (string)$c['phone']; $recent = wa_prod_recent($pdo, $phone, 24); $force = !empty($opt['force']); $all = null; @ignore_user_abort(true);
    foreach ($rows as $i => $p) {
        $nm = (string)$p['name'];
        if ((int)$p['active'] !== 1) { $res['skipped'][] = ['id' => $p['id'], 'name' => $nm, 'why' => 'Not on the website any more.']; continue; }
        if (!$p['photos']) { $res['skipped'][] = ['id' => $p['id'], 'name' => $nm, 'why' => 'This perfume has no photo on the website.']; continue; }
        $used = $recent[$p['id']] ?? [];
        if ($used && !$force && ($who === 'bot' || $mode === 'why')) { $res['skipped'][] = ['id' => $p['id'], 'name' => $nm, 'why' => 'Already sent in the last 24 hours.']; continue; }
        $idx = null; foreach (array_keys($p['photos']) as $k) if (!in_array($k, $used, true)) { $idx = $k; break; }
        if ($idx === null) { if (!$force) { $res['skipped'][] = ['id' => $p['id'], 'name' => $nm, 'why' => 'All photos were sent in the last 24 hours.', 'recent' => true]; continue; } $idx = 0; }
        $why = trim((string)($opt['why'][$p['id']] ?? '')); if ($mode === 'why' && $why === '') { $all = $all ?? wa_prod_all($pdo); $why = wa_prod_why($p, $all, []); }
        $caption = wa_prod_caption($p, $mode, ['why' => $why]); $url = wa_prod_img_url($p['photos'][$idx]);
        [$ok, $r] = wa_send_image_link($pdo, $c, $url, $caption, $who, $empId, $p['id']); $via = 'link';
        if (!$ok && !preg_match('/opted out|24-hour|WA_PHONE_ID|token|OAuth|permission|131047|131026|131048|131056|spam|rate limit|too many/i', (string)$r)) {
            $first = (string)$r; [$ok, $r] = wa_prod_send_via_library($pdo, $c, $url, $p, $caption, $who, $empId); $via = 'upload'; if (!$ok) $r = $first . ' (also tried uploading: ' . $r . ')';
        }
        if ($ok) { wa_prod_seen_mark($pdo, $phone, $p['id'], (int)$idx, (string)$r['wamid']); $recent[$p['id']][] = (int)$idx; $res['sent'][] = ['id' => $p['id'], 'name' => $nm, 'photo_idx' => (int)$idx, 'wamid' => (string)$r['wamid'], 'via' => $via]; }
        else $res['errors'][] = ['id' => $p['id'], 'name' => $nm, 'error' => (string)$r];
        if (empty($opt['nosleep']) && $i < count($rows) - 1) usleep(400000);   // keeps the photos in order on the customer's phone
    }
    if (!$res['sent'] && $res['errors']) { $res['ok'] = false; $res['error'] = $res['errors'][0]['error']; }
    return $res;
}

// ------------------------------------------------------------------------------------------------------------------ what the prepared reply carries (products + Photos tab)
/** The product whose name appears in a text (the bot's own reply). Used only by the practice chat, to answer "how long does it last?" about the product the bot just named. */
function wa_prod_name_in_text(PDO $pdo, string $text): ?array
{
    $f = ' ' . wa_prod_fold($text, false) . ' '; $best = null; $bl = 0;
    foreach (wa_prod_all($pdo) as $p) { if ($p['kind'] !== 'perfume') continue; $n = wa_prod_fold((string)$p['name'], false); if (strlen($n) >= 5 && strlen($n) > $bl && strpos($f, ' ' . $n . ' ') !== false) { $best = $p; $bl = strlen($n); } }
    return $best;
}
/** The ONE decision the live bot, the prepared reply and the practice chat all use. Reads only — it writes nothing. $opt: reply (practice chat: the bot's text), ignore_recent.
 *  Returns ['ids'=>[product ids], 'why'=>[id=>text], 'lib'=>[items], 'flag'=>'', 'asks_long'=>bool, 'q'=>'', 'lib_intent'=>[…], 'prod_intent'=>bool]. Max 3 pictures in all. */
function wa_prod_decide(PDO $pdo, string $phone, string $text, array $opt = []): array
{
    $o = ['ids' => [], 'why' => [], 'lib' => [], 'flag' => '', 'asks_long' => false, 'q' => trim($text), 'lib_intent' => [], 'prod_intent' => false];
    $in = wa_prod_intent($pdo, $text); $lin = wa_prod_lib_intent($pdo, $text); $o['asks_long'] = $in['asks_long']; $o['lib_intent'] = $lin; $yes = $in['yes'];
    // "price list bhejo" / "box ki photo" asks for a picture from the Photos tab, not for a perfume suggestion
    if ($yes && $lin && !$in['named'] && !preg_match('/\b(perfumes?|attars?|fragrances?|scents?)\b/', wa_prod_fold($text, false)) && array_intersect(array_column($lin, 'section'), ['packaging', 'sizes', 'delivery', 'store', 'candle'])) $yes = false;
    $o['prod_intent'] = $yes; $recent = $phone !== '' && empty($opt['ignore_recent']) ? wa_prod_recent($pdo, $phone, 24) : [];
    if ($lin) $o['lib'] = wa_prod_lib_pick($pdo, $phone, $lin, $yes ? 2 : 3, $opt);
    $room = (int)($opt['room'] ?? 3) - count($o['lib']);
    if ($yes && $room > 0) {
        $ex = array_keys($recent); $picks = [];
        if ($in['again']) {
            $nm = $phone !== '' ? wa_prod_last_asked($pdo, $phone) : ''; if ($nm === '' && !empty($opt['reply'])) { $hit = wa_prod_name_in_text($pdo, (string)$opt['reply']); if ($hit) $nm = (string)$hit['name']; }
            if ($nm !== '') $picks = wa_prod_pick($pdo, $nm, 1, ['exclude' => $ex, 'strict' => true, 'parse' => wa_prod_parse($pdo, $nm)]);
        } else {
            $similar = !$in['named'] || preg_match('/\b(type|jaisa|jaisi|like|similar|alternative|options?|suggest\w*)\b/', wa_prod_fold($text, false));
            $picks = wa_prod_pick($pdo, (string)$in['q'], $room, ['exclude' => $ex, 'fill_similar' => (bool)$similar, 'parse' => $in['parse']]);
        }
        foreach (array_slice($picks, 0, $room) as $it) {
            $o['ids'][] = (int)$it['id']; $w = (string)$it['why'];
            if ($in['asks_long'] && trim((string)$it['longevity']) === '') { $w = 'I will confirm how long it lasts'; if ($o['flag'] === '') $o['flag'] = 'The customer asked how long ' . $it['name'] . ' lasts, but it is not on the website. Please confirm it before you reply.'; }
            $o['why'][(int)$it['id']] = $w;
        }
    }
    return $o;
}
/** Up to 3 pictures (Shopify products and Photos-tab photos together) for the customer's newest message (the app shows them under the suggested reply; the photos go after the text when the countdown is not cancelled). Cached per message. */
function wa_prod_attached(PDO $pdo, array $c, array $opt = []): array
{
    $o = ['products' => [], 'lib' => [], 'items' => [], 'flag' => '', 'in_id' => 0, 'q' => '', 'asks_long' => false, 'skipped' => ''];
    if (!wa_prod_tables($pdo) || !$c) return $o; $phone = (string)$c['phone'];
    if (function_exists('wag_flow_active') && wag_flow_active($pdo, $phone)) { $o['skipped'] = 'flow'; return $o; }   // a guided flow is running: no automatic product replies
    if ((int)($c['optout'] ?? 0) === 1) { $o['skipped'] = 'optout'; return $o; }
    if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) { $o['skipped'] = 'window'; return $o; }
    $q = $pdo->prepare("SELECT id, body, mtype FROM wa_messages WHERE phone = ? AND dir = 'in' ORDER BY id DESC LIMIT 1"); $q->execute([$phone]); $m = $q->fetch();
    if (!$m) return $o; $o['in_id'] = (int)$m['id']; if ($m['mtype'] !== 'text') return $o;
    if (empty($opt['fresh'])) {
        $s = $pdo->prepare("SELECT * FROM wa_sugg_prod WHERE phone = ? AND in_id = ?"); $s->execute([$phone, $m['id']]); $row = $s->fetch();
        if ($row) { $ids = (array)json_decode((string)$row['ids_json'], true); $why = (array)json_decode((string)$row['why_json'], true); $o['q'] = (string)$row['q']; $o['flag'] = (string)$row['flag']; return wa_prod_attached_out($pdo, $o, $ids, $why, (array)json_decode((string)($row['lib_json'] ?? ''), true)); }
    }
    $d = wa_prod_decide($pdo, $phone, (string)$m['body'], isset($opt['room']) ? ['room' => (int)$opt['room']] : []); $o['asks_long'] = $d['asks_long']; $o['q'] = $d['q']; $libIds = array_map(fn($x) => (int)$x['ph_id'], $d['lib']);
    if ($d['flag'] !== '' && function_exists('wa_event')) wa_event($pdo, $phone, 'ℹ️ ' . $d['flag']);
    if (empty($opt['room']) || (int)$opt['room'] <= 3) try { $pdo->prepare("REPLACE INTO wa_sugg_prod (phone, in_id, ids_json, q, why_json, flag, created_at, lib_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$phone, $m['id'], json_encode($d['ids']), mb_substr($d['q'], 0, 200), json_encode($d['why'], JSON_UNESCAPED_UNICODE), mb_substr($d['flag'], 0, 160), date('Y-m-d H:i:s'), json_encode($libIds)]); } catch (PDOException $e) { }
    $o['flag'] = $d['flag']; return wa_prod_attached_out($pdo, $o, $d['ids'], $d['why'], $libIds);
}
function wa_prod_attached_out(PDO $pdo, array $o, array $ids, array $why, array $libIds = []): array
{
    $rows = wa_prod_get($pdo, $ids); $all = wa_prod_all($pdo); $o['products'] = []; $o['lib'] = [];
    foreach ($rows as $p) {
        if ((int)$p['active'] !== 1) continue; $w = (string)($why[$p['id']] ?? '');
        $it = wa_prod_item($p, $all, ['occ' => [], 'labels' => []], false); $it['kind'] = 'product'; $it['why'] = $w; $it['caption'] = wa_prod_caption($p, 'why', ['why' => $w]); $o['products'][] = $it;
    }
    if ($libIds) { $g = wa_prod_lib_get($pdo, $libIds); foreach ($libIds as $id) if (isset($g[(int)$id])) $o['lib'][] = wa_prod_lib_item($g[(int)$id]); }
    $o['items'] = array_merge($o['products'], $o['lib']); return $o;
}
/** Read-only preview for the practice chat (Teach → Test): what the live bot would attach to this customer message. Writes nothing (no seen rows, no cache, no events). */
function wa_prod_try(PDO $pdo, string $customer, string $reply = ''): array
{
    $o = ['products' => [], 'lib' => [], 'items' => [], 'flag' => '', 'why' => '', 'asks_long' => false, 'q' => trim($customer), 'empty_catalogue' => false];
    if (!wa_prod_tables($pdo)) return $o; $o['empty_catalogue'] = (int)$pdo->query("SELECT COUNT(*) FROM wa_prod WHERE active = 1")->fetchColumn() === 0;
    $d = wa_prod_decide($pdo, '', $customer, ['reply' => $reply]); $o['flag'] = $d['flag']; $o['asks_long'] = $d['asks_long'];
    $o = wa_prod_attached_out($pdo, $o, $d['ids'], $d['why'], array_map(fn($x) => (int)$x['ph_id'], $d['lib']));
    $why = []; foreach ($d['lib_intent'] as $x) $why[] = 'asked for the ' . mb_strtolower($x['label']); if ($d['ids']) $why[] = 'asked about perfumes';
    $o['why'] = $why ? 'The customer ' . implode(' and ', $why) . '.' : 'Nothing in this message asks for a photo.'; return $o;
}
/** Server bot only: after its text went out, the same pictures follow. Called from wag_bot_scan (one guarded line). Returns the number of photos sent. */
function wa_prod_bot_after(PDO $pdo, array $c, string $botText = ''): int
{
    try {
        if (function_exists('wa_photo_gate') && !wa_photo_gate($pdo)) return 0;   // 🧠 the learning says photos do not help right now
        $c = wa_contact($pdo, (string)$c['phone']) ?: $c; $a = wa_prod_attached($pdo, $c, ['room' => 6, 'fresh' => true]); $ids = []; $libs = []; $why = [];
        foreach ($a['items'] as $it) { if (is_string($it['id']) && strpos($it['id'], 'lib:') === 0) $libs[] = $it['id']; else $ids[] = (int)$it['id']; } foreach ($a['products'] as $p) $why[$p['id']] = (string)$p['why'];
        // 📸 FIXED RULE (owner, 10 Nov): whenever the bot's own words name a perfume of ours, its picture goes with it — even when the customer's message alone did not match the catalogue (e.g. "Azz The Most Desired")
        if ($botText !== '' && count($ids) < 6) { foreach (wa_prod_find_named($pdo, $botText, 6) as $p) { if (count($ids) >= 6) break; if (!in_array((int)$p['id'], $ids, true)) { $ids[] = (int)$p['id']; $why[(int)$p['id']] = 'I suggested this perfume'; } } }
        $n = 0;
        if ($ids) $n += function_exists('wa_collage_send') ? wa_collage_send($pdo, $c, $ids, 'bot', 0, $why) : (int)count((wa_prod_send_items($pdo, $c, $ids, 'why', 'bot', 0, ['why' => $why]))['sent'] ?? []);
        if ($libs) { $r = wa_prod_send_items($pdo, $c, $libs, 'why', 'bot', 0, ['why' => $why]); $n += count($r['sent'] ?? []); }
        return $n;
    } catch (Throwable $e) { error_log('wa_prod_bot_after: ' . $e->getMessage()); return 0; }
}
/** 🔎 Perfumes of ours that a text talks about — by OUR name, by the original perfume it is inspired by (website spelling, or the real original worked out in Products), in the order they appear.
 *  Several of ours can be inspired by the same original: then the best seller of them is taken. Used for "the bot / follow-up named a perfume → its picture goes with it". Max $max product rows. */
function wa_prod_find_named(PDO $pdo, string $text, int $max = 6): array
{
    $f = ' ' . wa_prod_fold($text, false) . ' '; $by = []; $hit = [];
    foreach (wa_prod_all($pdo) as $p) {
        if ($p['kind'] !== 'perfume') continue; $p = wa_prod_dec($p);
        foreach ([['n', (string)$p['name']], ['o', (string)($p['original_site'] ?? '')], ['o', (string)($p['original_real'] ?? '')]] as [$kind, $raw]) {
            $n = wa_prod_fold($raw, false); if (strlen($n) < 5) continue; $pos = strpos($f, ' ' . $n . ' '); if ($pos === false) continue;
            if ($kind === 'n') { $hit[(int)$p['id']] = [$pos, $p]; }
            else { $g = $n; if (!isset($by[$g]) || (int)$p['units_sold'] > (int)$by[$g][1]['units_sold']) $by[$g] = [$pos, $p]; }
        }
    }
    foreach ($by as $x) if (!isset($hit[(int)$x[1]['id']])) $hit[(int)$x[1]['id']] = $x;
    uasort($hit, fn($a, $b) => $a[0] <=> $b[0]); return array_slice(array_map(fn($x) => $x[1], array_values($hit)), 0, $max);
}
/** 🤖 Follow-up Autopilot, inside the 24-hour window (owner, 10 Nov: 100% automatic): after the follow-up TEXT went out, the perfumes it talks about — the ones named in the text, else the one the customer last asked about —
 *  follow as ONE picture (photo, notes, longevity). Same learning gate as the bot (photos pause by themselves when they stop helping). Outside the 24-hour window WhatsApp allows only approved templates, so nothing is added there. Returns perfumes shown. */
function wa_prod_follow_after(PDO $pdo, array $c, string $text): int
{
    try {
        if (function_exists('wa_photo_gate') && !wa_photo_gate($pdo)) return 0; if ((string)setting_get($pdo, 'wa_follow_photos', '1') === '0') return 0;
        if (empty($c['last_in_at']) || strtotime((string)$c['last_in_at']) < time() - 86400) return 0;
        $ids = array_map(fn($p) => (int)$p['id'], wa_prod_find_named($pdo, $text, 6));
        if (!$ids) { $nm = wa_prod_last_asked($pdo, (string)$c['phone']); if ($nm !== '') { $h = wa_prod_name_in_text($pdo, $nm); if ($h) $ids[] = (int)$h['id']; } }
        return $ids && function_exists('wa_collage_send') ? wa_collage_send($pdo, $c, $ids, 'bot', 0, []) : 0;
    } catch (Throwable $e) { error_log('wa_prod_follow_after: ' . $e->getMessage()); return 0; }
}
// ------------------------------------------------------------------------------------------------------------------ status (Products tab)
/** How many good Photos-tab photos the bot can attach, per section (Products tab note). */
function wa_prod_lib_counts(PDO $pdo): array
{
    $defs = wa_prod_lib_defs(); $n = []; foreach (wa_prod_lib_rows($pdo) as $r) $n[$r['section']] = ($n[$r['section']] ?? 0) + 1;
    $o = []; $tot = 0; foreach ($defs as $k => $d) { $o[] = ['k' => $k, 't' => $d[0], 'n' => (int)($n[$k] ?? 0)]; $tot += (int)($n[$k] ?? 0); }
    return ['sections' => $o, 'total' => $tot];
}
function wa_prod_status(PDO $pdo): array
{
    $rows = $pdo->query("SELECT * FROM wa_prod WHERE active = 1 ORDER BY units_sold DESC, id")->fetchAll(); $perf = array_values(array_filter($rows, fn($r) => $r['kind'] === 'perfume'));
    $cnt = ['products' => count($rows), 'perfumes' => count($perf), 'inactive' => (int)$pdo->query("SELECT COUNT(*) FROM wa_prod WHERE active = 0")->fetchColumn(), 'with_photos' => 0, 'with_notes' => 0, 'with_longevity' => 0, 'with_seasons' => 0, 'low_conf' => 0, 'with_original' => 0];
    $gaps = []; $dom = 'tmfragrance.myshopify.com'; if (function_exists('shopify_stores')) { $st = shopify_stores(); $f = reset($st); if (!empty($f['domain'])) $dom = (string)$f['domain']; }
    foreach ($perf as $p) {
        $miss = []; $ph = (array)json_decode((string)$p['photos_json'], true); $nt = (array)json_decode((string)$p['notes_json'], true); $se = (array)json_decode((string)$p['seasons_json'], true);
        if ($ph) $cnt['with_photos']++; else $miss[] = 'photo'; if ($nt) $cnt['with_notes']++; else $miss[] = 'notes'; if ($p['longevity'] !== '') $cnt['with_longevity']++; else $miss[] = 'longevity (missing or not in hours)'; if ($se) $cnt['with_seasons']++; else $miss[] = 'season';
        if ($p['original_real'] !== '') $cnt['with_original']++; if ((int)$p['orig_conf'] < 60) { $cnt['low_conf']++; $miss[] = 'original perfume not sure'; }
        if ($miss) $gaps[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'missing' => $miss, 'n' => count($miss), 'shopify_url' => 'https://' . $dom . '/admin/products/' . $p['shopify_id']];
    }
    usort($gaps, fn($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['name'], $b['name']));
    $occ = []; foreach ($pdo->query("SELECT * FROM wa_prod_occ ORDER BY active DESC, hits DESC, sort, slug")->fetchAll() as $r) $occ[] = ['slug' => $r['slug'], 'label' => $r['label'], 'group' => $r['grp'], 'hits' => (int)$r['hits'], 'source' => $r['source'], 'active' => (int)$r['active']];
    $info = json_decode((string)wa_prod_cfg($pdo, 'wa_prod_sync_info', '{}'), true); $info = is_array($info) ? $info : [];
    $ai = (function_exists('convo_gemini_key') && convo_gemini_key() !== '') || (function_exists('tmph_claude_key') && tmph_claude_key() !== '');
    $with90 = 0; foreach ($perf as $p) if ($p['units_90d'] !== null) $with90++;
    $snapDays = (int)$pdo->query("SELECT COUNT(DISTINCT day) FROM wa_prod_snap")->fetchColumn();
    return ['counts' => $cnt, 'last_sync_at' => (string)wa_prod_cfg($pdo, 'wa_prod_last_sync_at', ''), 'last_sync_day' => (string)wa_prod_cfg($pdo, 'wa_prod_last_sync', ''), 'synced_today' => (string)wa_prod_cfg($pdo, 'wa_prod_last_sync', '') === date('Y-m-d'),
        'sync' => $info, 'error' => !empty($info['failed']) || (isset($info['ok']) && !$info['ok']) ? (string)($info['error'] ?? '') : '', 'ai' => ['configured' => $ai, 'error' => (string)wa_prod_cfg($pdo, 'wa_prod_ai_err', ''), 'pending' => wa_prod_pending_count($pdo)],
        'gaps' => array_slice($gaps, 0, 100), 'gaps_total' => count($gaps), 'occ' => $occ, 'sales' => ['with_90d' => $with90, 'snapshot_days' => $snapDays, 'note' => $snapDays >= 8 ? '' : 'Sales for the last 30 and 90 days need about a week of daily copies. Until then the all-time count is used.'],
        'library' => wa_prod_lib_counts($pdo), 'mine' => ['last_id' => (int)wa_prod_cfg($pdo, 'wa_prod_mine_id', '0')], 'auto' => (string)wa_prod_cfg($pdo, 'wa_prod_auto', '1') !== '0', 'rules' => ['max_per_reply' => 3, 'no_repeat_hours' => 24]];
}

// ------------------------------------------------------------------------------------------------------------------ router
}   // function_exists guard

$__wpa = (string)($action ?? ($_GET['action'] ?? ''));
if (in_array($__wpa, ['wa.prod_search', 'wa.prod_notes', 'wa.prod_suggest', 'wa.prod_attached', 'wa.prod_try', 'wa.prod_send', 'wa.prod_status', 'wa.prod_sync', 'wa.prod_enrich', 'wa.prod_occ_list', 'wa.prod_occ_toggle', 'wa.prod_auto'], true)) {
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $auth = require_auth(); $me = (int)($auth['employee_id'] ?? 0);
    if (!wa_tables($pdo) || !wa_prod_tables($pdo)) json_response(['error' => 'Product storage is not available — check database permissions.'], 500);
    $GLOBALS['__wp_pdo'] = $pdo; $in = $isPost ? json_input() : $_GET; $adm = wa_is_admin($pdo, $auth);
    switch ($__wpa) {
        case 'wa.prod_status': { wa_guard_admin($pdo, $auth); json_response(wa_prod_status($pdo)); }
        case 'wa.prod_sync': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); wa_guard_admin($pdo, $auth); @set_time_limit(150); @ignore_user_abort(true);
            $r = wa_prod_sync($pdo); if (!empty($r['ok'])) { try { $r['mine'] = wa_prod_mine($pdo); } catch (Throwable $e) { $r['mine'] = ['error' => 'mining stopped']; } }
            json_response(['ok' => !empty($r['ok']), 'error' => (string)($r['error'] ?? ''), 'result' => $r, 'pending' => wa_prod_pending_count($pdo)], !empty($r['ok']) || !empty($r['busy']) ? 200 : 502);
        }
        case 'wa.prod_enrich': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); wa_guard_admin($pdo, $auth); @set_time_limit(150);
            if (!wa_lock($pdo, 'prod_enrich', 20)) json_response(['ok' => false, 'error' => 'An AI batch is already running.', 'left' => wa_prod_pending_count($pdo)], 409);
            try { $r = wa_prod_enrich($pdo, 15, ['force' => true]); } finally { wa_prod_unlock($pdo, 'prod_enrich'); }
            json_response($r + ['pending' => wa_prod_pending_count($pdo)]);
        }
        case 'wa.prod_auto': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); wa_guard_admin($pdo, $auth); $on = !empty($in['on']); setting_set($pdo, 'wa_prod_auto', $on ? '1' : '0'); json_response(['ok' => true, 'auto' => $on]);
        }
        case 'wa.prod_occ_toggle': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); wa_guard_admin($pdo, $auth); $slug = (string)($in['slug'] ?? ''); $act = !empty($in['active']) ? 1 : 0;
            $u = $pdo->prepare("UPDATE wa_prod_occ SET active = ? WHERE slug = ?"); $u->execute([$act, $slug]);
            $q = $pdo->prepare("SELECT COUNT(*) FROM wa_prod_occ WHERE slug = ?"); $q->execute([$slug]); if (!(int)$q->fetchColumn()) json_response(['error' => 'Request type not found.'], 404);
            json_response(['ok' => true, 'slug' => $slug, 'active' => $act]);
        }
        case 'wa.prod_occ_list': {
            $items = []; $by = []; foreach (wa_prod_occ_rows($pdo) as $r) { if (!$adm && !(int)$r['active']) continue; $by[$r['slug']] = $r; $items[] = ['slug' => $r['slug'], 'label' => $r['label'], 'group' => $r['grp'], 'hits' => (int)$r['hits'], 'source' => $r['source'], 'active' => (int)$r['active']]; }
            $chips = []; foreach (['winter', 'summer', 'office', 'date-night', 'party', 'gift', 'best-sellers'] as $s) if (isset($by[$s]) && (int)$by[$s]['active']) $chips[] = ['slug' => $s, 'label' => $by[$s]['label'], 'q' => mb_strtolower($by[$s]['label'])];
            $extra = array_values(array_filter($items, fn($i) => $i['active'] && $i['group'] !== 'who' && !in_array($i['slug'], array_column($chips, 'slug'), true) && $i['hits'] > 0)); usort($extra, fn($a, $b) => $b['hits'] <=> $a['hits']);
            foreach (array_slice($extra, 0, 5) as $i) $chips[] = ['slug' => $i['slug'], 'label' => $i['label'], 'q' => mb_strtolower($i['label'])];
            json_response(['items' => $items, 'chips' => $chips, 'can_edit' => $adm]);
        }
        case 'wa.prod_search':
        case 'wa.prod_suggest': {
            $kind = (string)($in['kind'] ?? 'perfume'); if (!in_array($kind, ['perfume', 'gel', 'candle', 'other', 'any'], true)) $kind = 'perfume';
            $total = (int)$pdo->query("SELECT COUNT(*) FROM wa_prod WHERE active = 1")->fetchColumn(); $syncAt = (string)wa_prod_cfg($pdo, 'wa_prod_last_sync_at', '');
            $phone = ''; $recent = []; $kk = wa_key($in['phone'] ?? ''); if ($kk !== '') { $cc = wa_contact($pdo, $kk); if ($cc && wa_can_chat($pdo, $auth, $cc)) { $phone = $kk; $recent = wa_prod_recent($pdo, $kk, 24); } }
            if (!empty($in['ids'])) {
                $all = wa_prod_all($pdo); $rows = wa_prod_get($pdo, array_slice(array_map('intval', is_array($in['ids']) ? $in['ids'] : explode(',', (string)$in['ids'])), 0, 12)); $items = [];
                foreach ($rows as $p) $items[] = wa_prod_item($p, $all, ['occ' => [], 'labels' => []], true);
                json_response(['items' => $items, 'total' => $total]);
            }
            $q = trim((string)($in['q'] ?? ($in['occasion'] ?? ''))); $lim = max(1, min(40, (int)($in['limit'] ?? ($in['n'] ?? ($__wpa === 'wa.prod_suggest' ? 3 : 20)))));
            $parse = wa_prod_parse($pdo, $q); $isSearch = $__wpa === 'wa.prod_search';
            $items = wa_prod_pick($pdo, $q === '' ? 'best sellers' : $q, $lim, ['kind' => $kind, 'staff' => true, 'strict' => $isSearch, 'fill_similar' => !$isSearch && !empty($parse['words']) ? true : false]);
            foreach ($items as &$it) $it['sent_recent'] = isset($recent[$it['id']]); unset($it);
            json_response(['items' => $items, 'total' => $total, 'empty' => $total === 0, 'synced_at' => $syncAt, 'parsed' => ['occ' => $parse['labels'], 'who' => $parse['who'], 'words' => $parse['words']], 'q' => $q, 'phone' => $phone]);
        }
        case 'wa.prod_notes': {   // 🌿 the customer's top / heart / base notes (or a perfume they named) → our closest perfumes with a % match
            $top = mb_substr((string)($in['top'] ?? ''), 0, 300); $mid = mb_substr((string)($in['mid'] ?? ''), 0, 300); $base = mb_substr((string)($in['base'] ?? ''), 0, 300); $nm = mb_substr((string)($in['name'] ?? ''), 0, 120);
            if (trim($top . $mid . $base . $nm) === '') json_response(['error' => 'Type at least one note.'], 422);
            json_response(wa_prod_notes_match($pdo, $top, $mid, $base, $nm));
        }
        case 'wa.prod_attached': {
            $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            json_response(wa_prod_attached($pdo, $c, ['fresh' => !empty($in['fresh'])]));
        }
        case 'wa.prod_try': {   // read-only: what the live bot would attach to this customer message (+ the bot's reply text). Writes nothing.
            if ($isPost) json_response(['error' => 'Method not allowed.'], 405);
            json_response(wa_prod_try($pdo, mb_substr(trim((string)($in['q'] ?? '')), 0, 600), mb_substr(trim((string)($in['reply'] ?? '')), 0, 1500)));
        }
        case 'wa.prod_send': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $key = wa_key($in['phone'] ?? ''); $c = $key !== '' ? wa_contact($pdo, $key) : null; if (!$c) json_response(['error' => 'Chat not found.'], 404);
            if (!wa_can_chat($pdo, $auth, $c)) json_response(['error' => 'This chat is assigned to someone else.'], 403);
            $ids = is_array($in['ids'] ?? null) ? $in['ids'] : []; $mode = (string)($in['mode'] ?? 'photo'); $why = [];
            if ($mode === 'why') { $s = $pdo->prepare("SELECT why_json FROM wa_sugg_prod WHERE phone = ?"); $s->execute([$key]); $why = (array)json_decode((string)$s->fetchColumn(), true); }
            $r = wa_prod_send_items($pdo, $c, $ids, $mode, 'emp', $me, ['force' => !empty($in['force']), 'why' => $why, 'lib' => is_array($in['lib_ids'] ?? null) ? $in['lib_ids'] : []]);
            if (isset($r['error']) && !$r['sent']) json_response(['error' => $r['error'], 'sent' => [], 'skipped' => $r['skipped'] ?? [], 'errors' => $r['errors'] ?? []], !empty($r['errors']) ? 502 : 422);
            json_response($r);
        }
    }
}
