<?php
/**
 * price_list.php — "💰 Price list & freedom" for Sales → Learn & Setup → Practice bot → ⚙️ Facts.
 * Included from api.php (one line, see the install note) and answers only the actions that start with "pl.".
 *
 *   pl.get          GET                      → the saved price list (+ whether a price photo is stored)
 *   pl.read_photo   POST {image(base64), mime} → the AI reads the price photo once and returns a DRAFT table (nothing is saved)
 *   pl.photo        GET                      → the stored price photo as a data URL (for the preview)
 *   pl.voice        POST {audio(base64), mime, lang} → the spoken instructions as text (Gemini listens; Claude can't hear audio)
 *   pl.understand   POST {draft…}            → "what the bot understood", in plain words — nothing is saved
 *   pl.save         POST {draft…}            → saves it and switches it ON for the bot
 *
 * How it plugs into the bot WITHOUT touching its code: saving writes two fenced blocks into the facts the bot already reads —
 *   • the size table + the owner's instructions → the "Prices" / "Rules" facts (the bot may quote ONLY what is written there), and
 *   • one "size = lowest allowed price" line per size → the Discount budget's floor list, which the system already enforces
 *     (a draft below a floor is thrown away). With "freedom" ON, the floor is the lowest price minus the freedom, never more.
 * Hand-written text in those facts is kept; only the fenced blocks are replaced.
 */
if (!function_exists('pl_clean')) {

define('PL_P0', '### PRICE LIST (written by the Price list box — edit it there, not here) ###');
define('PL_P1', '### END PRICE LIST ###');
define('PL_F0', '# price list start');
define('PL_F1', '# price list end');
define('PL_R0', '### HOW TO USE THE PRICES (owner\'s own words, written by the Price list box) ###');
define('PL_R1', '### END HOW TO USE THE PRICES ###');

function pl_dir(): string
{
    $d = __DIR__ . '/uploads/pricelist/';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if (is_dir($d) && !is_file($d . '.htaccess')) @file_put_contents($d . '.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    return $d;
}

function pl_defaults(): array
{
    return ['rows' => [], 'tiers' => ['chart' => 7.0, 'offer' => 6.0, 'low' => 2.8], 'free' => ['on' => false, 'pct' => 3.0, 'rs' => 100, 'once' => true],
            'instructions' => '', 'photo' => false, 'saved_at' => ''];
}

/** Clean what the screen sent. Returns [clean, warnings]. */
function pl_clean($in): array
{
    $in = is_array($in) ? $in : []; $d = pl_defaults(); $warn = [];
    $num = fn($v) => is_numeric(str_replace(',', '', (string)$v)) ? (float)str_replace(',', '', (string)$v) : 0.0;
    $rows = []; $seen = [];
    foreach (array_slice((array)($in['rows'] ?? []), 0, 150) as $r) {
        if (!is_array($r)) continue;
        $ml = (int)round($num($r['ml'] ?? 0)); if ($ml < 1 || $ml > 5000 || isset($seen[$ml])) continue;
        $c = max(0, min(10000000, (int)round($num($r['chart'] ?? 0)))); $o = max(0, min(10000000, (int)round($num($r['offer'] ?? 0)))); $l = max(0, min(10000000, (int)round($num($r['lowest'] ?? 0))));
        if ($c + $o + $l === 0) continue;
        if ($o === 0) $o = $c; if ($l === 0) $l = $o; if ($c === 0) $c = max($o, $l);       // a missing step uses the one above it
        if ($c < $o || $o < $l) { $warn[] = $ml . ' ml: the prices should go chart ≥ offer ≥ lowest (₹' . $c . ' / ₹' . $o . ' / ₹' . $l . ').'; }
        $seen[$ml] = 1; $rows[] = ['ml' => $ml, 'chart' => $c, 'offer' => $o, 'lowest' => $l];
    }
    usort($rows, fn($a, $b) => $a['ml'] <=> $b['ml']);
    $d['rows'] = $rows;
    $t = (array)($in['tiers'] ?? []);
    foreach (['chart', 'offer', 'low'] as $k) if (isset($t[$k]) && is_numeric($t[$k])) $d['tiers'][$k] = max(0, min(100, round((float)$t[$k], 2)));
    $f = (array)($in['free'] ?? []);
    $d['free'] = ['on' => !empty($f['on']), 'pct' => max(0, min(30, round($num($f['pct'] ?? 0), 2))), 'rs' => max(0, min(5000, (int)round($num($f['rs'] ?? 0)))), 'once' => !isset($f['once']) || !empty($f['once'])];
    if ($d['free']['on'] && $d['free']['pct'] <= 0 && $d['free']['rs'] <= 0) { $d['free']['on'] = false; $warn[] = 'Freedom is ON but both the % and the ₹ are 0, so it is switched off.'; }
    $d['instructions'] = mb_substr(trim((string)($in['instructions'] ?? '')), 0, 3000);
    $d['photo'] = is_file(pl_dir() . 'photo.jpg');
    return [$d, $warn];
}

/** The lowest price the bot may EVER quote for a row: the lowest price minus the freedom (the smaller of the % and the ₹ when both are set), in whole ₹10. */
function pl_floor(array $row, array $free): int
{
    $low = (int)$row['lowest']; if (empty($free['on'])) return $low;
    $p = $free['pct'] > 0 ? $low * $free['pct'] / 100 : null; $r = $free['rs'] > 0 ? (float)$free['rs'] : null;
    $room = $p !== null && $r !== null ? min($p, $r) : ($p ?? $r ?? 0.0);
    return max(0, (int)(ceil(($low - $room) / 10) * 10));
}

function pl_block_prices(array $P): string
{
    if (!$P['rows']) return '';
    $t = PL_P0 . "\nPrice per size in rupees: CHART = start here and quote this first · OFFER = give it when the customer asks for a discount or hesitates · LOWEST = the normal bottom price.\n";
    foreach ($P['rows'] as $r) {
        $t .= $r['ml'] . ' ml: chart ₹' . $r['chart'] . ' | offer ₹' . $r['offer'] . ' | lowest ₹' . $r['lowest'];
        if (!empty($P['free']['on'])) $t .= ' | absolute floor ₹' . pl_floor($r, $P['free']);
        $t .= "\n";
    }
    if (!empty($P['free']['on'])) $t .= "ABSOLUTE FLOOR = the very last price, only for a customer who still hesitates after the lowest price" . (!empty($P['free']['once']) ? ', and only once' : '') . ". Never go below it.\n";
    return $t . PL_P1;
}
function pl_block_rules(array $P): string
{
    $x = trim((string)$P['instructions']);
    $t = PL_R0 . "\n";
    if (!empty($P['free']['on'])) $t .= 'You may go below the LOWEST price only when the customer still hesitates after you gave the lowest price' . (!empty($P['free']['once']) ? ' (once)' : '') . ', never below the absolute floor of that size. Never mention the lowest price or the floor before the customer pushes.' . "\n";
    if ($x !== '') $t .= $x . "\n";
    return trim($t) === trim(PL_R0) ? '' : $t . PL_R1;
}
function pl_block_floors(array $P): string
{
    if (!$P['rows']) return '';
    $t = PL_F0 . "\n"; foreach ($P['rows'] as $r) $t .= $r['ml'] . ' ml = ' . pl_floor($r, $P['free']) . "\n";
    return $t . PL_F1;
}

/** Remove a fenced block, keep everything else, then add the new block (if any) at the end. */
function pl_swap(string $text, string $a, string $b, string $new): string
{
    $text = preg_replace('/\s*' . preg_quote($a, '/') . '.*?' . preg_quote($b, '/') . '\s*/su', "\n", $text);
    $text = trim((string)$text);
    return $new === '' ? $text : ($text === '' ? $new : $text . "\n\n" . $new);
}

function pl_load(PDO $pdo): array
{
    $P = pl_defaults();
    try { $q = $pdo->prepare("SELECT body, updated_at FROM lib_facts WHERE k = 'pricelist'"); $q->execute(); $r = $q->fetch();
        if ($r) { $j = json_decode((string)$r['body'], true); if (is_array($j)) { [$P] = pl_clean($j); $P['saved_at'] = (string)$r['updated_at']; } } } catch (PDOException $e) {}
    return $P;
}

function pl_save(PDO $pdo, array $P): ?string
{
    $F = teach_facts_get($pdo);
    $prices = pl_swap((string)$F['prices'], PL_P0, PL_P1, pl_block_prices($P));
    $rules = pl_swap((string)$F['offers'] === '' ? (string)$F['rules'] : (string)$F['rules'], PL_R0, PL_R1, pl_block_rules($P));
    $B = $F['budget']; $B['floors'] = pl_swap((string)$B['floors'], PL_F0, PL_F1, pl_block_floors($P));
    if (mb_strlen($B['floors']) > 1500) return 'The list of lowest prices is too long (' . mb_strlen($B['floors']) . ' of 1500 characters). Remove some sizes, or shorten the lowest-price lines you typed by hand in the Discount budget.';
    if (mb_strlen($prices) > 12000 || mb_strlen($rules) > 12000) return 'Too much text for the bot to read. Shorten the instructions.';
    teach_facts_set($pdo, 'prices', $prices);
    teach_facts_set($pdo, 'rules', $rules);
    teach_facts_set($pdo, 'budget', json_encode(teach_budget_clean($B), JSON_UNESCAPED_UNICODE));
    $P['saved_at'] = date('Y-m-d H:i:s');
    teach_facts_set($pdo, 'pricelist', json_encode($P, JSON_UNESCAPED_UNICODE));
    return null;
}

/** "What I understood": the numbers in plain words (always right, built from the table) + the owner's instructions restated by the AI. */
function pl_summary(array $P): array
{
    $lines = []; $rows = $P['rows'];
    if (!$rows) return ['lines' => ['There is no price table yet — the bot will not quote any price from this box.'], 'ai' => []];
    $e = null; foreach ($rows as $r) if ($r['ml'] === 300) { $e = $r; break; } if (!$e) $e = $rows[(int)floor(count($rows) / 2)];
    $m = $e['ml'] . ' ml'; $f = $P['free'];
    $lines[] = 'I know ' . count($rows) . ' sizes (' . $rows[0]['ml'] . '–' . $rows[count($rows) - 1]['ml'] . ' ml).';
    $lines[] = 'I start every quote at the CHART price (for example ' . $m . ' = ₹' . number_format($e['chart']) . ').';
    $lines[] = 'If the customer asks for less or hesitates, I give the OFFER price (' . $m . ' = ₹' . number_format($e['offer']) . ').';
    $lines[] = 'The normal bottom is the LOWEST price (' . $m . ' = ₹' . number_format($e['lowest']) . '). I do not offer it first.';
    if (!empty($f['on'])) $lines[] = 'If the customer STILL hesitates after the lowest price, I may go a little lower' . (!empty($f['once']) ? ', once' : '') . ' — but never below ₹' . number_format(pl_floor($e, $f)) . ' for ' . $m . ' (' . ($f['pct'] > 0 ? 'up to ' . rtrim(rtrim(number_format($f['pct'], 2), '0'), '.') . '%' : '') . ($f['pct'] > 0 && $f['rs'] > 0 ? ' or ' : '') . ($f['rs'] > 0 ? '₹' . $f['rs'] : '') . ' below the lowest' . ($f['pct'] > 0 && $f['rs'] > 0 ? ', whichever is smaller' : '') . ').';
    else $lines[] = 'I never go below the LOWEST price.';
    $lines[] = 'Any price that is not in this table (or in your lessons) gets thrown away and rewritten, or I hand the chat to a person.';
    return ['lines' => $lines, 'ai' => []];
}

function pl_ai_bullets(array $P): array
{
    $x = trim((string)$P['instructions']); if ($x === '' || !function_exists('convo_ai')) return [];
    $sys = "You are a WhatsApp sales bot for a perfume shop. The owner wrote instructions for you about prices, in his own words (English, Hindi or Gujarati). Restate what you understood as 3 to 7 short plain-English bullet points, each starting with what you WILL do or will NOT do. Use only what he wrote — do not add rules, numbers or promises of your own. If something is unclear, say it as a question in a bullet that starts with 'Not sure:'.";
    $schema = ['type' => 'OBJECT', 'properties' => ['bullets' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['bullets']];
    try { [$ok, $v] = convo_ai($sys, [['text' => "The owner's instructions:\n" . $x]], $schema, false, 1500, 'claude'); } catch (Throwable $e) { return []; }
    if (!$ok || !is_array($v)) return [];
    return array_values(array_slice(array_filter(array_map(fn($b) => mb_substr(trim((string)$b), 0, 300), (array)($v['bullets'] ?? []))), 0, 7));
}

}   // function_exists guard

$__pl = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__pl, 'pl.') === 0) {
    $auth = require_auth(); lib_guard($pdo, $auth);
    if (!ensure_teach_table($pdo)) json_response(['error' => 'Could not prepare the Teach tables.'], 500);
    @set_time_limit(150);
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    switch ($__pl) {
        case 'pl.get': {
            $P = pl_load($pdo); json_response(['p' => $P]);
        }
        case 'pl.photo': {
            $f = pl_dir() . 'photo.jpg'; if (!is_file($f)) json_response(['error' => 'No price photo stored yet.'], 404);
            json_response(['mime' => 'image/jpeg', 'data' => base64_encode((string)file_get_contents($f))]);
        }
        case 'pl.read_photo': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $b64 = preg_replace('/^data:[^,]*,/', '', (string)($in['image'] ?? '')); $bin = base64_decode((string)$b64, true);
            if ($bin === false || strlen($bin) < 500) json_response(['error' => 'The photo could not be read — pick it again.'], 422);
            if (strlen($bin) > 9000000) json_response(['error' => 'The photo is too big (max 9 MB).'], 422);
            $mime = (string)($in['mime'] ?? 'image/jpeg'); if (!preg_match('#^image/(jpeg|png|webp)$#', $mime)) $mime = 'image/jpeg';
            @file_put_contents(pl_dir() . 'photo.jpg', $bin);
            $sys = "You read a perfume shop's PRICE CHART photo and copy it into a table. One row per bottle size in ml. Each row has up to three prices in rupees: 'chart' (the highest price, the normal list price), 'offer' (the middle price) and 'lowest' (the lowest price). The photo may label the columns by commission percentages (like 7%, 6%, 2.8%) or by names; the highest-priced column is chart, the next is offer, the lowest is lowest. If the chart has only one or two price columns, put 0 in the missing ones. Copy every number EXACTLY — no rounding, no guessing; if a digit is unreadable put 0 and mention it in notes. Skip rows that are not sizes. 'notes' = one short sentence about anything unclear.";
            $schema = ['type' => 'OBJECT', 'properties' => [
                'rows' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => ['ml' => ['type' => 'INTEGER'], 'chart' => ['type' => 'INTEGER'], 'offer' => ['type' => 'INTEGER'], 'lowest' => ['type' => 'INTEGER']], 'required' => ['ml', 'chart', 'offer', 'lowest']]],
                'notes' => ['type' => 'STRING']], 'required' => ['rows', 'notes']];
            ignore_user_abort(true);
            [$ok, $v, $why] = convo_ai($sys, [['text' => 'The price chart photo:'], ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bin)]]], $schema, true, 8000);
            if (!$ok) json_response(['error' => $why ?: 'The AI could not read the photo.'], 502);
            [$C, $w] = pl_clean(['rows' => (array)($v['rows'] ?? [])]);
            if (!$C['rows']) json_response(['error' => 'I could not find any size/price rows in this photo. Try a clearer, straighter photo.'], 422);
            json_response(['ok' => true, 'rows' => $C['rows'], 'notes' => mb_substr((string)($v['notes'] ?? ''), 0, 300), 'warnings' => $w]);
        }
        case 'pl.voice': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            $in = json_input(); $b64 = preg_replace('/^data:[^,]*,/', '', (string)($in['audio'] ?? '')); $bin = base64_decode((string)$b64, true);
            if ($bin === false || strlen($bin) < 800) json_response(['error' => 'That recording is empty — record again.'], 422);
            if (strlen($bin) > 9000000) json_response(['error' => 'The recording is too long (max about 5 minutes).'], 422);
            $mime = strtolower(trim(explode(';', (string)($in['mime'] ?? 'audio/webm'))[0])); if (!preg_match('#^(audio|video)/[a-z0-9.+-]+$#', $mime)) $mime = 'audio/webm';
            $lang = ['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English'][(string)($in['lang'] ?? '')] ?? 'Gujarati, Hindi or English';
            $sys = "You listen to a voice note from a perfume shop owner explaining how the sales bot should use prices (chart price, offer price, lowest price, when to give a discount). The language is probably $lang (it may be mixed). Write down EXACTLY what he said, in the language he spoke (Gujarati in Gujarati script, Hindi in Devanagari, English in English; keep brand and price words as he said them), as clean sentences. Do not add anything he did not say. Then give a short plain-English version in 'english'.";
            $schema = ['type' => 'OBJECT', 'properties' => ['text' => ['type' => 'STRING'], 'english' => ['type' => 'STRING']], 'required' => ['text', 'english']];
            ignore_user_abort(true);
            [$ok, $v, $why] = convo_ai($sys, [['text' => 'The voice note:'], ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($bin)]]], $schema, true, 4000);
            if (!$ok) json_response(['error' => $why ?: 'The AI could not listen to the recording.'], 502);
            json_response(['ok' => true, 'text' => trim((string)($v['text'] ?? '')), 'english' => trim((string)($v['english'] ?? ''))]);
        }
        case 'pl.understand': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            [$P, $w] = pl_clean(json_input()); $S = pl_summary($P); $S['ai'] = pl_ai_bullets($P);
            $S['instructions_empty'] = trim($P['instructions']) === '';
            json_response(['ok' => true, 'summary' => $S, 'warnings' => $w]);
        }
        case 'pl.save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
            [$P, $w] = pl_clean(json_input());
            if (!$P['rows'] && trim($P['instructions']) === '') json_response(['error' => 'Add the price table (upload the photo or type the rows) before saving.'], 422);
            $err = pl_save($pdo, $P); if ($err !== null) json_response(['error' => $err], 422);
            $facts = teach_facts_get($pdo);
            json_response(['ok' => true, 'warnings' => $w, 'saved_at' => $P['saved_at'], 'prices' => $facts['prices'], 'rules' => $facts['rules'], 'budget' => $facts['budget']]);
        }
        default: json_response(['error' => 'Unknown price list action.'], 404);
    }
}