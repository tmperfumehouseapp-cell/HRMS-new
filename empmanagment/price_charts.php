<?php
/**
 * price_charts.php — ONE "Price charts" section for every segment. Staff only: the % columns are never shown to a customer.
 *
 *   Segments: perfume · attar · gel (shower gel) · other (wax and the rest). Perfume is priced by its TOTAL ml; attar, shower gel and others are
 *   fixed sizes (attar 3 ml, shower gel 200 ml …) and are priced by QUANTITY (1 piece, 2 pieces, 3 …) — their row "ml" field holds the number of pieces.
 *   A chart = rows (total ml, or pieces) × columns (salesperson commission %: 7 · 6.5 · 6 · 4 · 3.5 · 3, floor 2.8 below the last column).
 *   Perfume = the existing Price & Commission slab chart (setting ml_slab_chart) — the same data, so commission keeps working exactly as before.
 *   The other segments live in settings price_chart_<seg> and start with the perfume columns (editable per segment).
 *
 *   Who uses it: the cart (list / lowest price per segment, commission per segment), the WhatsApp bot (pc_bot_text: the prices,
 *   and which column to quote is taught on the Teach board), and the customer price photo (size + ONE column, no %).
 *
 *   Customer price photo: drawn by the Price charts page (size / pieces + ONE column's price, no %), stored as uploads/pricephoto/<token>.jpg and served
 *   without login by pc.photo_view&t=<token> (a random token, so the link cannot be guessed). The bot sends it when the Teach rules say so.
 *
 *   Actions (pc.*): pc.get · pc.save · pc.suggest · pc.cust_col · pc.photo_save · pc.photo_view (public)
 */
if (!function_exists('pc_segs')) {

function pc_segs(): array { return ['perfume' => 'Perfume', 'attar' => 'Attar', 'gel' => 'Shower gel', 'other' => 'Others']; }

// ------------------------------------------------------------------ Combos: ANY mix of kinds (perfume ml, shower gel, attar, others) is ONE row with EXACTLY those amounts.
// The owner creates the row; the cart / commission never work a mixed price out on their own. One kind alone uses its own rows.
/** The combo rows: {perf (total perfume ml, 0 = none), gel, attar, other (pieces), p[]} with the perfume % columns. */
function pc_mix_get(PDO $pdo): array
{
    $p = pc_perfume($pdo); $j = json_decode((string)setting_get($pdo, 'price_chart_mix', ''), true); if (!is_array($j)) $j = [];
    $rows = []; foreach ((array)($j['rows'] ?? []) as $r) if (is_array($r)) $rows[] = ['perf' => (int)($r['perf'] ?? 0), 'gel' => (int)($r['gel'] ?? 0), 'attar' => (int)($r['attar'] ?? 0), 'other' => (int)($r['other'] ?? 0), 'p' => array_values(array_map('floatval', (array)($r['p'] ?? [])))];
    return ['seg' => 'mix', 'cols' => array_values(array_map('floatval', (array)($j['cols'] ?? $p['cols']))), 'names' => array_values((array)($j['names'] ?? $p['names'])), 'rows' => $rows, 'floor' => (float)($j['floor'] ?? $p['floor']), 'mode' => $p['mode'], 'updated_at' => (string)($j['updated_at'] ?? '')];
}
/** How many kinds a set of amounts has (perfume counts as one kind). */
function pc_mix_kinds(int $perf, int $g, int $a, int $x): int { return ($perf > 0) + ($g > 0) + ($a > 0) + ($x > 0); }
/** A few common combos to start from (the owner adds the ones the team really sells). */
function pc_mix_suggest(): array
{
    $o = []; foreach ([[100, 1, 0, 0], [100, 0, 1, 0], [100, 1, 1, 0], [150, 1, 2, 0], [200, 1, 1, 0], [0, 1, 1, 0], [0, 2, 2, 0]] as [$pm, $g, $a, $x]) $o[] = ['perf' => $pm, 'gel' => $g, 'attar' => $a, 'other' => $x];
    return $o;
}
function pc_mix_clean(array $in): array
{
    [$C, $err] = pc_clean(['cols' => $in['cols'] ?? [], 'names' => $in['names'] ?? [], 'rows' => [], 'floor' => $in['floor'] ?? 2.8]); if ($err) return [null, $err];
    $rows = []; $seen = [];
    foreach (array_slice((array)($in['rows'] ?? []), 0, 300) as $r) {
        if (!is_array($r)) continue; $pm = max(0, min(20000, (int)($r['perf'] ?? 0))); $g = max(0, min(50, (int)($r['gel'] ?? 0))); $a = max(0, min(50, (int)($r['attar'] ?? 0))); $x = max(0, min(50, (int)($r['other'] ?? 0)));
        if (pc_mix_kinds($pm, $g, $a, $x) < 2) continue;   // a combo is 2 or more kinds (one kind alone is its own rows)
        $k = "$pm|$g|$a|$x"; if (isset($seen[$k])) continue;
        $p = []; $any = false; foreach ($C['cols'] as $i => $_) { $v = ($r['p'] ?? [])[$i] ?? ''; $v = is_numeric($v) ? max(0, round((float)$v)) : 0; if ($v > 0) $any = true; $p[] = $v; }
        if (!$any) continue;
        for ($i = 1; $i < count($p); $i++) if ($p[$i] > 0 && $p[$i - 1] > 0 && $p[$i] > $p[$i - 1]) return [null, 'Combo ' . pc_mix_label($pm, $g, $a, $x) . ': the prices must go down from left to right.'];
        $seen[$k] = 1; $rows[] = ['perf' => $pm, 'gel' => $g, 'attar' => $a, 'other' => $x, 'p' => $p];
    }
    $C['rows'] = $rows; return [$C, null];
}
/** The combo row for EXACTLY these amounts, or null (then the owner has to create it). */
function pc_mix_prices(PDO $pdo, int $perf, int $g, int $a, int $x): ?array
{
    if (pc_mix_kinds($perf, $g, $a, $x) < 2) return null; $M = pc_mix_get($pdo);   // read fresh (one setting): the row may have just been saved
    foreach ($M['rows'] as $r) if ($r['perf'] === $perf && $r['gel'] === $g && $r['attar'] === $a && $r['other'] === $x) { $nz = array_values(array_filter($r['p'], function ($v) { return $v > 0; })); if (!$nz) return null;
        return ['seg' => 'mix', 'p' => $r['p'], 'list' => $nz[0], 'lowest' => end($nz), 'cols' => $M['cols'], 'floor' => $M['floor']]; }
    return null;
}
/** The commission % of a combo at a price: at or above a column's price = that column's %; below the last = the floor. null = no row yet. */
function pc_mix_pct(PDO $pdo, int $perf, int $g, int $a, int $x, float $price): ?float
{
    $m = pc_mix_prices($pdo, $perf, $g, $a, $x); if (!$m) return null;
    foreach ($m['p'] as $i => $v) if ($v > 0 && $price >= $v - 0.001) return (float)$m['cols'][$i];
    return (float)$m['floor'];
}
function pc_mix_label(int $perf, int $g, int $a, int $x): string { $p = []; if ($perf) $p[] = 'perfume ' . $perf . ' ml'; if ($g) $p[] = $g . ' shower gel'; if ($a) $p[] = $a . ' attar'; if ($x) $p[] = $x . ' other'; return implode(' + ', $p); }
/** How a segment is counted: perfume by total ml, the fixed-size segments by pieces. */
function pc_unit(string $seg): string { return $seg === 'perfume' ? 'ml' : 'pcs'; }

/** Suggested total-ml rows for a new chart. */
function pc_suggest_rows(string $seg): array
{
    if ($seg === 'perfume') return range(50, 500, 10);
    return range(1, 10);   // attar / shower gel / others: 1 to 10 pieces
}

/** The perfume slab chart (cols / names / rows / floor / mode) — read through ml_slab_get, so its migrations still run. */
function pc_perfume(PDO $pdo): array
{
    $c = function_exists('ml_slab_get') ? ml_slab_get($pdo) : [];
    return ['cols' => array_values(array_map('floatval', (array)($c['cols'] ?? [7, 6.5, 6, 4, 3.5, 3]))), 'names' => array_values((array)($c['names'] ?? [])),
        'rows' => array_values((array)($c['rows'] ?? [])), 'floor' => (float)($c['floor'] ?? 2.8), 'mode' => (string)($c['mode'] ?? 'step'), 'updated_at' => (string)($c['updated_at'] ?? '')];
}

/** One segment's chart. A segment never saved starts with the perfume columns and no rows. */
function pc_get(PDO $pdo, string $seg): array
{
    if ($seg === 'perfume') return pc_perfume($pdo) + ['seg' => 'perfume'];
    $p = pc_perfume($pdo);
    $j = function_exists('setting_get') ? json_decode((string)setting_get($pdo, 'price_chart_' . $seg, ''), true) : null;
    if (!is_array($j)) $j = ['cols' => $p['cols'], 'names' => $p['names'], 'rows' => [], 'floor' => $p['floor'], 'updated_at' => ''];
    return ['seg' => $seg, 'cols' => array_values(array_map('floatval', (array)($j['cols'] ?? $p['cols']))), 'names' => array_values((array)($j['names'] ?? $p['names'])), 'rows' => array_values((array)($j['rows'] ?? [])),
        'floor' => (float)($j['floor'] ?? $p['floor']), 'mode' => $p['mode'], 'updated_at' => (string)($j['updated_at'] ?? '')];
}

/** Tidy a chart from the screen. Returns [chart, error]. Empty price cells are allowed (a row still being filled); a row with no price at all is dropped. */
function pc_clean(array $in): array
{
    $cols = []; foreach (array_slice((array)($in['cols'] ?? []), 0, 10) as $c) { if (!is_numeric($c)) continue; $c = round((float)$c, 2); if ($c >= 0 && $c <= 30) $cols[] = $c; }
    if (count($cols) < 2) return [null, 'Give at least two % columns.'];
    for ($i = 1; $i < count($cols); $i++) if ($cols[$i] >= $cols[$i - 1]) return [null, 'The % columns must go down from left to right (for example 7, 6.5, 6 …).'];
    $names = []; foreach ($cols as $i => $_) $names[] = mb_substr(trim((string)(($in['names'] ?? [])[$i] ?? '')), 0, 30);
    $rows = []; $seen = [];
    foreach (array_slice((array)($in['rows'] ?? []), 0, 400) as $r) {
        if (!is_array($r) || !is_numeric($r['ml'] ?? null)) continue; $ml = round((float)$r['ml'], 1); if ($ml <= 0 || $ml > 20000 || isset($seen[(string)$ml])) continue;
        $p = []; $any = false; foreach ($cols as $i => $_) { $v = ($r['p'] ?? [])[$i] ?? ''; $v = is_numeric($v) ? max(0, round((float)$v)) : 0; if ($v > 0) $any = true; $p[] = $v; }
        if (!$any) continue;
        for ($i = 1; $i < count($p); $i++) if ($p[$i] > 0 && $p[$i - 1] > 0 && $p[$i] > $p[$i - 1]) return [null, $ml . ' ml: the prices must go down from left to right (higher % = higher price).'];
        $seen[(string)$ml] = 1; $rows[] = ['ml' => $ml, 'p' => $p];
    }
    usort($rows, function ($a, $b) { return $a['ml'] <=> $b['ml']; });
    $floor = is_numeric($in['floor'] ?? null) ? max(0, min(30, round((float)$in['floor'], 2))) : 2.8;
    return [['cols' => $cols, 'names' => $names, 'rows' => $rows, 'floor' => $floor], null];
}

/** Save one segment. Perfume writes the rows / columns / names into the existing slab chart and keeps every other slab setting. */
function pc_save(PDO $pdo, string $seg, array $C, string $by): ?string
{
    $C['updated_at'] = date('Y-m-d H:i:s'); $C['updated_by'] = $by;
    if ($seg === 'perfume') {
        $cur = json_decode((string)setting_get($pdo, 'ml_slab_chart', ''), true); if (!is_array($cur) || empty($cur['rows'])) $cur = function_exists('ml_slab_default') ? ml_slab_default() : [];
        foreach ($C['rows'] as $r) foreach ($r['p'] as $i => $v) if ($v <= 0 && abs((float)($C['cols'][$i] ?? 0) - 5.0) > 0.001) return 'Perfume is used for commission: fill every price of every row (only the 5% column may stay blank), or remove the row.';
        $new = array_merge($cur, ['cols' => $C['cols'], 'names' => $C['names'], 'rows' => $C['rows'], 'floor' => $C['floor'], 'updated_at' => $C['updated_at'], 'updated_by' => $by]);
        if (function_exists('ml_slab_clean')) { [$chk, $err] = ml_slab_clean($new); if ($err) return $err; $new = array_merge($new, $chk, ['updated_at' => $C['updated_at'], 'updated_by' => $by]); }
        setting_set($pdo, 'ml_slab_chart', json_encode($new, JSON_UNESCAPED_UNICODE)); return null;
    }
    setting_set($pdo, 'price_chart_' . $seg, json_encode($C, JSON_UNESCAPED_UNICODE)); return null;
}

/** Prices of one segment for a TOTAL ml: the row of that ml, else the nearest lower row (the chart's own rule). null when the ml is below the first row or the chart is empty. */
function pc_prices(PDO $pdo, string $seg, float $ml): ?array
{
    static $memo = []; if (!isset($memo[$seg])) $memo[$seg] = pc_get($pdo, $seg); $C = $memo[$seg];
    if ($ml <= 0 || !$C['rows']) return null; $row = null; foreach ($C['rows'] as $r) if ((float)$r['ml'] <= $ml + 0.001) $row = $r;
    if (!$row) return null; $p = array_values(array_map('floatval', (array)$row['p'])); $nz = array_values(array_filter($p, function ($v) { return $v > 0; })); if (!$nz) return null;
    return ['seg' => $seg, 'ml' => (float)$row['ml'], 'exact' => abs((float)$row['ml'] - $ml) < 0.001, 'p' => $p, 'list' => $nz[0], 'lowest' => end($nz), 'cols' => $C['cols']];
}

/** The commission % a part of an order earns on its segment's chart (same rule as the perfume slab: at or above a column's price = that column's %). */
function pc_pct(PDO $pdo, string $seg, float $ml, float $price): ?float
{
    $C = pc_get($pdo, $seg); if (!$C['rows'] || $ml <= 0) return null;
    if (function_exists('coach_slab_pct')) { try { return coach_slab_pct(['rows' => $C['rows'], 'cols' => $C['cols'], 'floor' => $C['floor'], 'mode' => $C['mode']], $ml, $price, false); } catch (Throwable $e) { } }
    $x = pc_prices($pdo, $seg, $ml); if (!$x) return null; foreach ($x['p'] as $i => $v) if ($v > 0 && $price >= $v) return (float)$x['cols'][$i]; return (float)$C['floor'];
}

/** Which column customers see as "the price" (the price photo, and the column the bot quotes first unless the Teach rules say otherwise). */
function pc_cust_col(PDO $pdo): int { return max(0, (int)setting_get($pdo, 'price_chart_cust_col', '0')); }

/** Every chart for the WhatsApp bot: prices by TOTAL ml per segment, column by column. Which column to quote is taught on the Teach board (selling rules). */
function pc_bot_text(PDO $pdo): string
{
    $t = ''; $cc = pc_cust_col($pdo);
    foreach (pc_segs() as $seg => $lb) {
        $C = pc_get($pdo, $seg); if (!$C['rows']) continue; $h = [];
        foreach ($C['cols'] as $i => $p) $h[] = rtrim(rtrim(number_format($p, 2), '0'), '.') . '%' . (($C['names'][$i] ?? '') !== '' ? ' (' . $C['names'][$i] . ')' : '');
        $t .= strtoupper($lb) . " PRICE CHART — rupees for the " . (pc_unit($seg) === 'ml' ? 'TOTAL ' . strtolower($lb) . ' ml' : 'NUMBER OF ' . strtolower($lb) . ' PIECES (fixed size)') . " in the order; columns from the highest price down: " . implode(', ', $h) . ". For a total between two rows use the lower row. The % are internal (salesperson commission levels) — NEVER mention a % to the customer.\n";
        foreach ($C['rows'] as $r) { $ps = []; foreach ($r['p'] as $i => $v) if ($v > 0) $ps[] = rtrim(rtrim(number_format((float)$C['cols'][$i], 2), '0'), '.') . '% ₹' . (int)$v; $t .= (0 + $r['ml']) . (pc_unit($seg) === 'ml' ? ' ml' : ' pc') . ': ' . implode(' | ', $ps) . "\n"; }
    }
    $M = pc_mix_get($pdo);
    if ($M['rows']) { $t .= "COMBO PRICES — an order with 2 or more kinds (perfume ml, shower gel, attar, others) has ONE price for EXACTLY that combination. Quote ONLY a combo listed here; for a combination not listed, say the team will confirm the price:\n";
        foreach ($M['rows'] as $r) { $ps = []; foreach ($r['p'] as $i => $v) if ($v > 0) $ps[] = rtrim(rtrim(number_format((float)$M['cols'][$i], 2), '0'), '.') . '% ₹' . (int)$v; $t .= pc_mix_label($r['perf'], $r['gel'], $r['attar'], $r['other']) . ': ' . implode(' | ', $ps) . "\n"; } }
    if ($t === '') return '';
    $col = pc_get($pdo, 'perfume')['cols'][$cc] ?? null;
    return "PRICE CHARTS (the shop's one price source: perfume by its total ml; attar, shower gel and others by the number of pieces; a mixed order = each part from its own chart):\n" . $t
        . ($col !== null ? 'The customer price photo shows the ' . rtrim(rtrim(number_format((float)$col, 2), '0'), '.') . "% column. Which column to quote first and when to move to another is in the SELLING RULES.\n" : '');
}


// ------------------------------------------------------------------ ↩ history (every save keeps the version before it — "Undo" puts it back) + 🧮 the combo builder
function pc_hist_key(string $seg): string { return $seg === 'perfume' ? 'ml_slab_chart' : 'price_chart_' . $seg; }
function pc_hist(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'price_chart_hist', ''), true); return is_array($j) ? $j : []; }
/** Keep the current version of a segment before it changes (last 40 versions in all). */
function pc_hist_push(PDO $pdo, string $seg, string $by, string $why): void
{
    $cur = (string)setting_get($pdo, pc_hist_key($seg), ''); $h = pc_hist($pdo);
    $h[] = ['seg' => $seg, 'at' => date('Y-m-d H:i:s'), 'by' => $by, 'why' => mb_substr($why, 0, 120), 'json' => $cur];
    setting_set($pdo, 'price_chart_hist', json_encode(array_slice($h, -40), JSON_UNESCAPED_UNICODE));
}
/** ↩ Put back the version before the last change of a segment. Returns an error or null. */
function pc_undo(PDO $pdo, string $seg): ?string
{
    $h = pc_hist($pdo); for ($i = count($h) - 1; $i >= 0; $i--) if ($h[$i]['seg'] === $seg) {
        setting_set($pdo, pc_hist_key($seg), (string)$h[$i]['json']); array_splice($h, $i, 1); setting_set($pdo, 'price_chart_hist', json_encode($h, JSON_UNESCAPED_UNICODE)); return null; }
    return 'There is no earlier version of this section to go back to.';
}
/** Round to the shop's price endings: …49 or …99 (₹849, ₹899, ₹1,499). */
function pc_r99(float $x): int { return max(49, (int)(round(($x + 1) / 50) * 50) - 1); }
/** One line of the owner's combo text: "1 gel - 499", "2 gel + 1 50 - 1799" (1 bottle of 50 ml), "1 gel + 2 100 - 2599", "1 attar + 1 100ml = 1299".
 *  Returns ['perf' => total perfume ml, 'gel', 'attar', 'other', 'price'] or null. */
function pc_combo_line(string $l): ?array
{
    $l = mb_strtolower(trim($l)); if ($l === '' || !preg_match('/^(.*?)[\s]*[-=:₹]+\s*₹?\s*([\d,]+)\s*$/u', $l, $m)) return null;
    $price = (int)str_replace(',', '', $m[2]); if ($price <= 0) return null; $o = ['perf' => 0, 'gel' => 0, 'attar' => 0, 'other' => 0, 'price' => $price];
    foreach (preg_split('/\+|,|&|\band\b/u', $m[1]) as $part) { $part = trim($part); if ($part === '') continue;
        if (preg_match('/^(\d+)\s*(x\s*)?(gel|gels|shower ?gel|sg)\b/u', $part, $x)) $o['gel'] += (int)$x[1];
        elseif (preg_match('/^(\d+)\s*(x\s*)?(attar|attars)\b/u', $part, $x)) $o['attar'] += (int)$x[1];
        elseif (preg_match('/^(\d+)\s*(x\s*)?(wax|other|others)\b/u', $part, $x)) $o['other'] += (int)$x[1];
        elseif (preg_match('/^(\d+)\s*(?:x|×|\s)\s*(\d+)\s*(ml)?$/u', $part, $x)) $o['perf'] += (int)$x[1] * (int)$x[2];   // "2 50" = two bottles of 50 ml
        elseif (preg_match('/^(\d+)\s*ml$/u', $part, $x)) $o['perf'] += (int)$x[1];
        else return null; }
    return ($o['perf'] + $o['gel'] + $o['attar'] + $o['other']) > 0 ? $o : null;
}
/** How each % column compares to the base column (price at column ÷ price at base), from the owner's OWN data:
 *  the existing combo rows first (the "old" combos made at 7 / 6 / 5 / 3 %), else the parts' own charts (perfume ml + gel / attar / others pieces), else the perfume chart. */
function pc_combo_ratios(PDO $pdo, array $o, int $base, array $cols, bool $mix): array
{
    $med = function (array $a) { sort($a); $n = count($a); return $n ? ($n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2) : null; };
    $fromRows = function (array $rows) use ($base, $cols, $med) { $out = []; foreach ($cols as $c => $_) { $v = []; foreach ($rows as $r) { $p = (array)$r['p']; if (($p[$base] ?? 0) > 0 && ($p[$c] ?? 0) > 0) $v[] = $p[$c] / $p[$base]; } $out[$c] = count($v) >= 3 ? $med($v) : null; } return $out; };
    $own = $mix ? $fromRows(pc_mix_get($pdo)['rows']) : $fromRows(pc_get($pdo, 'gel')['rows']);
    $parts = array_fill(0, count($cols), 0.0); $ok = true;
    foreach (['perfume' => $o['perf'], 'gel' => $o['gel'], 'attar' => $o['attar'], 'other' => $o['other']] as $seg => $amt) { if ($amt <= 0) continue; $x = pc_prices($pdo, $seg, (float)$amt); if (!$x) { $ok = false; break; } foreach ($cols as $c => $_) { $v = (float)($x['p'][$c] ?? 0); if ($v <= 0) { $ok = false; break 2; } $parts[$c] += $v; } }
    $perf = $fromRows(pc_get($pdo, 'perfume')['rows']); $out = []; $src = [];
    foreach ($cols as $c => $_) {
        if ($c === $base) { $out[$c] = 1.0; continue; }
        if ($own[$c] !== null) { $out[$c] = $own[$c]; $src[] = 'your ' . ($mix ? 'combo' : 'shower gel') . ' rows'; }
        elseif ($ok && $parts[$base] > 0) { $out[$c] = $parts[$c] / $parts[$base]; $src[] = 'the parts\' own charts'; }
        elseif ($perf[$c] !== null) { $out[$c] = $perf[$c]; $src[] = 'the perfume chart'; }
        else { $out[$c] = 1 + ($cols[$c] - $cols[$base]) * 0.04; $src[] = 'a 4% step per column (no data)'; }
    }
    return [$out, implode(', ', array_unique($src))];
}
/** 🧮 The combo builder: the owner's lines at ONE column (e.g. 6 %) → every column, rounded to …99, prices going down from left to right.
 *  Lines with only shower gel / attar / others go to that section (pieces); 2+ kinds go to Combos. $apply = save (the old versions are kept for Undo). */
function pc_combo_plan(PDO $pdo, string $text, float $basePct, bool $apply, string $by): array
{
    $cols = pc_perfume($pdo)['cols']; $base = null; foreach ($cols as $i => $c) if (abs($c - $basePct) < 0.001) $base = $i;
    if ($base === null) return ['error' => 'There is no ' . rtrim(rtrim(number_format($basePct, 2), '0'), '.') . '% column in your price chart. The columns are: ' . implode(', ', array_map(fn($c) => rtrim(rtrim(number_format($c, 2), '0'), '.') . '%', $cols)) . '.'];
    $rows = []; $bad = [];
    foreach (preg_split('/\r?\n/', $text) as $l) { if (trim($l) === '') continue; $o = pc_combo_line($l); if (!$o) { $bad[] = trim($l); continue; }
        $kinds = pc_mix_kinds($o['perf'], $o['gel'], $o['attar'], $o['other']); $seg = $kinds >= 2 ? 'mix' : ($o['gel'] ? 'gel' : ($o['attar'] ? 'attar' : ($o['other'] ? 'other' : 'perfume')));
        if ($seg === 'perfume') { $bad[] = trim($l) . ' (perfume alone is priced by its own chart)'; continue; }
        [$ratio, $src] = pc_combo_ratios($pdo, $o, $base, $cols, $seg === 'mix');
        $p = []; foreach ($cols as $c => $_) $p[$c] = $c === $base ? $o['price'] : pc_r99($o['price'] * $ratio[$c]);
        for ($c = $base - 1; $c >= 0; $c--) $p[$c] = max($p[$c], $p[$c + 1]);              // left = higher price
        for ($c = $base + 1; $c < count($cols); $c++) $p[$c] = min($p[$c], $p[$c - 1]);
        $rk = $seg . '|' . $o['perf'] . '|' . $o['gel'] . '|' . $o['attar'] . '|' . $o['other']; foreach ($rows as $ri => $rr) if ($rr['key'] === $rk) unset($rows[$ri]); $rows = array_values($rows);   // the same combo twice (2 × 50 ml = 100 ml): the last line counts
        $rows[] = ['key' => $rk, 'line' => trim($l), 'seg' => $seg, 'perf' => $o['perf'], 'gel' => $o['gel'], 'attar' => $o['attar'], 'other' => $o['other'], 'label' => $seg === 'mix' ? pc_mix_label($o['perf'], $o['gel'], $o['attar'], $o['other']) : ($o['gel'] ?: ($o['attar'] ?: $o['other'])) . ' ' . pc_segs()[$seg], 'p' => $p, 'from' => $src];
    }
    $res = ['cols' => $cols, 'base' => $base, 'rows' => $rows, 'bad' => $bad];
    if (!$apply || !$rows) return $res;
    $bySeg = []; foreach ($rows as $r) $bySeg[$r['seg']][] = $r;
    foreach ($bySeg as $seg => $list) {
        pc_hist_push($pdo, $seg, $by, 'Combo builder: ' . count($list) . ' rows at ' . rtrim(rtrim(number_format($basePct, 2), '0'), '.') . '%');
        if ($seg === 'mix') { $M = pc_mix_get($pdo); $keep = []; foreach ($M['rows'] as $r) $keep[$r['perf'] . '|' . $r['gel'] . '|' . $r['attar'] . '|' . $r['other']] = $r;
            foreach ($list as $r) $keep[$r['perf'] . '|' . $r['gel'] . '|' . $r['attar'] . '|' . $r['other']] = ['perf' => $r['perf'], 'gel' => $r['gel'], 'attar' => $r['attar'], 'other' => $r['other'], 'p' => array_values($r['p'])];
            [$C, $err] = pc_mix_clean(['cols' => $cols, 'names' => $M['names'], 'floor' => $M['floor'], 'rows' => array_values($keep)]); if ($err) return $res + ['error' => $err];
            $C['updated_at'] = date('Y-m-d H:i:s'); setting_set($pdo, 'price_chart_mix', json_encode($C, JSON_UNESCAPED_UNICODE)); }
        else { $G = pc_get($pdo, $seg); $keep = []; foreach ($G['rows'] as $r) $keep[(string)(0 + $r['ml'])] = $r; foreach ($list as $r) { $n = $r['gel'] ?: ($r['attar'] ?: $r['other']); $keep[(string)$n] = ['ml' => $n, 'p' => array_values($r['p'])]; }
            [$C, $err] = pc_clean(['cols' => $cols, 'names' => $G['names'], 'floor' => $G['floor'], 'rows' => array_values($keep)]); if ($err) return $res + ['error' => $err]; $e2 = pc_save($pdo, $seg, $C, $by); if ($e2) return $res + ['error' => $e2]; }
    }
    if (function_exists('kb_touch')) kb_touch($pdo, 'Prices: combo builder');
    return $res + ['applied' => true];
}
/** The owner's combo list of 5 Nov 2026 (at the 6 % column) — put in once by itself, Undo takes it back. */
function pc_owner_combos_v1(): string
{
    return "1 gel - 499\n3 gel - 999\n5 gel - 1499\n1 gel + 1 20 - 899\n2 gel + 1 20 - 999\n3 gel + 2 20 - 1499\n1 gel + 1 50 - 1499\n1 gel + 2 50 - 2099\n2 gel + 1 50 - 1799\n1 gel + 1 100 - 2099\n2 gel + 1 100 - 2399\n1 gel + 2 100 - 2599";
}

function pc_photo_dir(): string { $d = __DIR__ . '/uploads/pricephoto/'; if (!is_dir($d)) @mkdir($d, 0755, true); return $d; }
function pc_base_url(): string
{
    $u = defined('APP_URL') ? rtrim((string)APP_URL, '/') : ''; if ($u !== '') return preg_replace('#/empmanagment$#', '', $u);
    return 'https://' . (string)($_SERVER['HTTP_HOST'] ?? 'fourpirates.space');
}
/** The public link of the current customer price photo, or '' when none was made yet. */
function pc_photo_url(PDO $pdo): string
{
    $t = preg_replace('/[^a-f0-9]/', '', (string)setting_get($pdo, 'price_photo_token', '')); if ($t === '' || !is_file(pc_photo_dir() . $t . '.jpg')) return '';
    return pc_base_url() . '/empmanagment/api.php?action=pc.photo_view&t=' . $t;
}


// ------------------------------------------------------------------ ONE pricing: Commission → Pricing (cm_chart) and Learn & Setup → Pricing (perfume = ml_slab_chart) stay the same prices.
// The two charts do not have the same % columns (commission: 7 · 6.5 · 6 · 5 · 4 · 3, price chart: 7 · 6.5 · 6 · 4 · 3.5 · 3), so rows are matched by ML and prices by the SAME % column.
// First time: the commission price wins for every ML in both; an ML in only one chart is added to the other. After that, every save on one side pushes its prices to the other (add / overwrite, never delete).
// Shower gel, attar, others and the combos have ONE store (price_chart_<seg> / price_chart_mix): the Commission Pricing tab shows and the Price Chart page edits the very same data.
/** which old-column index each new column has (same % value), null when there is none */
function price_col_map(array $from, array $to): array
{
    $m = []; foreach ($from as $i => $fc) { $m[$i] = null; foreach ($to as $j => $tc) if (abs((float)$fc - (float)$tc) < 0.001) { $m[$i] = $j; break; } } return $m;
}
/** prices must never rise from left to right (a blank 0 is skipped) */
function price_clamp(array $p): array
{
    $last = 0; foreach ($p as $i => $v) { $v = (float)$v; if ($v > 0) { if ($last > 0 && $v > $last) $v = $last; $last = $v; } $p[$i] = $v > 0 ? round($v) : 0; } return $p;
}
function price_cm_raw(PDO $pdo): ?array { $s = json_decode((string)setting_get($pdo, 'cm_chart', ''), true); return is_array($s) && !empty($s['rows']) && !empty($s['cols']) ? $s : null; }
/** ONE price chart for everything. The Price Chart (Learn & Setup → Pricing) is the only place prices live; the commission engine, the cart, the bot and the customer photo all read it.
 *  This runs ONCE (the first time an Admin / HR opens a pricing page after the update): it backs everything up, adds the 5% column the commission needs to EVERY section (blank where nobody
 *  priced it: a blank column changes nothing), takes the commission prices where the commission chart had them (they win for a size that is in both), adds the sizes that were only in the commission chart,
 *  keeps the customer-photo column pointing at the same %, and tells the bot to learn the new chart. Nothing is deleted: the old commission copy stays in the backup. */
function price_unify_once(PDO $pdo): void
{
    if ((string)setting_get($pdo, 'price_unify_v1', '') === '1') return;
    try {
        $pc = pc_perfume($pdo); $cm = price_cm_raw($pdo);
        if (empty($pc['rows'])) return;                                  // no price chart yet: try again later
        setting_set($pdo, 'price_unify_backup', json_encode(['at' => date('Y-m-d H:i:s'), 'ml_slab_chart' => (string)setting_get($pdo, 'ml_slab_chart', ''), 'cm_chart' => (string)setting_get($pdo, 'cm_chart', ''),
            'gel' => (string)setting_get($pdo, 'price_chart_gel', ''), 'attar' => (string)setting_get($pdo, 'price_chart_attar', ''), 'other' => (string)setting_get($pdo, 'price_chart_other', ''), 'mix' => (string)setting_get($pdo, 'price_chart_mix', ''),
            'cust_col' => (string)setting_get($pdo, 'price_chart_cust_col', '0')], JSON_UNESCAPED_UNICODE));
        $cols = array_map('floatval', $pc['cols']); $names = array_values((array)$pc['names']);
        $pos = null; foreach ($cols as $i => $c) { if (abs($c - 5.0) < 0.001) { $pos = -1; break; } if ($c < 5.0) { $pos = $i; break; } } if ($pos === null) $pos = count($cols);   // where 5% goes (the columns go down)
        $added = []; $est = [];
        // 1. perfume rows (the slab chart): add the 5% column, the commission prices win for a size in both, sizes only in the commission chart come in
        $newCols = $cols; $newNames = $names;
        if ($pos >= 0) { array_splice($newCols, $pos, 0, [5.0]); array_splice($newNames, $pos, 0, ['']); }
        $cmMap = $cm ? price_col_map(array_map('floatval', (array)$cm['cols']), $newCols) : [];     // commission column => new column
        $cmRows = []; if ($cm) foreach ((array)$cm['rows'] as $r) if (is_array($r)) $cmRows[(string)round((float)$r['ml'], 1)] = array_map('floatval', (array)($r['p'] ?? []));
        $rows = [];
        foreach ($pc['rows'] as $r) {
            $pp = array_map('floatval', (array)$r['p']); if ($pos >= 0) array_splice($pp, $pos, 0, [0.0]); $k = (string)round((float)$r['ml'], 1);
            if (isset($cmRows[$k])) foreach ($cmMap as $ci => $ni) if ($ni !== null && ($cmRows[$k][$ci] ?? 0) > 0) $pp[$ni] = $cmRows[$k][$ci];
            $rows[$k] = ['ml' => $r['ml'], 'p' => price_clamp($pp)];
        }
        foreach ($cmRows as $k => $cp) if (!isset($rows[$k])) {
            $pp = array_fill(0, count($newCols), 0.0); foreach ($cmMap as $ci => $ni) if ($ni !== null && ($cp[$ci] ?? 0) > 0) $pp[$ni] = $cp[$ci];
            foreach ($pp as $i => $v) if ($v <= 0 && abs($newCols[$i] - 5.0) > 0.001) {         // a price column the commission chart does not have (3.5%): between its neighbours, to be checked
                $prev = 0; for ($q = $i - 1; $q >= 0; $q--) if ($pp[$q] > 0) { $prev = $pp[$q]; break; } $next = 0; for ($q = $i + 1; $q < count($pp); $q++) if ($pp[$q] > 0) { $next = $pp[$q]; break; }
                $pp[$i] = $prev > 0 && $next > 0 ? round(($prev + $next) / 2) : ($prev > 0 ? $prev : $next); $est[$k] = 1; }
            $rows[$k] = ['ml' => (float)$k, 'p' => price_clamp($pp)]; $added[] = $k;
        }
        $rows = array_values($rows); usort($rows, function ($a, $b) { return (float)$a['ml'] <=> (float)$b['ml']; });
        [$C, $err] = pc_clean(['cols' => $newCols, 'names' => $newNames, 'rows' => $rows, 'floor' => $pc['floor']]); if ($err) throw new RuntimeException($err);
        // 2. every other section gets the same blank 5% column (their prices stay exactly as they are)
        $others = [];
        if ($pos >= 0) {
            foreach (['gel', 'attar', 'other', 'mix'] as $sg) {
                $key = $sg === 'mix' ? 'price_chart_mix' : 'price_chart_' . $sg; $j = json_decode((string)setting_get($pdo, $key, ''), true); if (!is_array($j) || empty($j['cols'])) continue;
                $jc = array_map('floatval', (array)$j['cols']); $has = false; foreach ($jc as $c) if (abs($c - 5.0) < 0.001) $has = true; if ($has) continue;
                $jp = null; foreach ($jc as $i => $c) if ($c < 5.0) { $jp = $i; break; } if ($jp === null) $jp = count($jc);
                array_splice($jc, $jp, 0, [5.0]); $jn = array_values((array)($j['names'] ?? [])); array_splice($jn, $jp, 0, ['']);
                foreach ((array)($j['rows'] ?? []) as $ri => $row) { $q = array_values((array)($row['p'] ?? [])); array_splice($q, $jp, 0, [0]); $j['rows'][$ri]['p'] = $q; }
                $j['cols'] = $jc; $j['names'] = $jn; $others[$key] = $j;
            }
        }
        // write everything (all computed above, so a failure leaves nothing half-done)
        $e = pc_save($pdo, 'perfume', $C, 'One price chart (update)'); if ($e) throw new RuntimeException($e);
        foreach ($others as $key => $j) setting_set($pdo, $key, json_encode($j, JSON_UNESCAPED_UNICODE));
        if ($pos >= 0) { $cc = (int)setting_get($pdo, 'price_chart_cust_col', '0'); if ($cc >= $pos) setting_set($pdo, 'price_chart_cust_col', (string)($cc + 1)); }     // the customer photo keeps showing the same %
        setting_set($pdo, 'price_unify_note', json_encode(['at' => date('Y-m-d H:i:s'), 'added' => $added, 'estimated' => array_keys($est), 'col5' => $pos >= 0], JSON_UNESCAPED_UNICODE));
        setting_set($pdo, 'price_unify_v1', '1');
        if (function_exists('kb_touch')) kb_touch($pdo, 'Prices: one price chart (5% column)');      // the Teach bot relearns the chart
    } catch (Throwable $e) { error_log('price_unify_once: ' . $e->getMessage()); }
}
}   // function_exists guard

// ------------------------------------------------------------------------------------------------------------------ actions
$__pc = (string)($action ?? ($_GET['action'] ?? ''));
if ($__pc === 'pc.photo_view') {   // public: the customer's phone (Meta) fetches the photo by its link
    $t = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? '')); $f = pc_photo_dir() . $t . '.jpg';
    if (strlen($t) < 16 || !is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=300'); header('Content-Length: ' . filesize($f)); readfile($f); exit;
}
if (strpos($__pc, 'pc.') === 0) {
    $auth = require_auth();
    $canEdit = in_array($auth['role'] ?? '', ['Admin', 'HR'], true) || (function_exists('user_can') && user_can($auth, 'commission_rules'));
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; $in = $isPost ? json_input() : $_GET;
    $seg = (string)($in['seg'] ?? 'perfume'); if (!isset(pc_segs()[$seg]) && $seg !== 'mix') json_response(['error' => 'Unknown segment.'], 422);
    switch ($__pc) {
        case 'pc.get': {   // every segment (the screen shows tabs); salespeople may read the charts, only Admin / HR may change them
            if ($canEdit) price_unify_once($pdo);   // ONE price chart: the first time, add the 5% column everywhere and take the commission prices in
            $o = []; foreach (pc_segs() as $k => $lb) $o[$k] = pc_get($pdo, $k) + ['label' => $lb, 'unit' => pc_unit($k), 'suggest' => pc_suggest_rows($k)];
            $auto = null; if ($canEdit && (string)setting_get($pdo, 'pc_owner_combos_v1', '') !== '1') { setting_set($pdo, 'pc_owner_combos_v1', '1'); $auto = pc_combo_plan($pdo, pc_owner_combos_v1(), 6, true, 'Owner list (5 Nov)'); }   // 🧮 the owner's list, put in once
            if ($auto) { foreach (pc_segs() as $k => $lb) $o[$k] = pc_get($pdo, $k) + ['label' => $lb, 'unit' => pc_unit($k), 'suggest' => pc_suggest_rows($k)]; }
            $o['mix'] = pc_mix_get($pdo) + ['label' => 'Mix (combos)', 'unit' => 'mix', 'suggest' => pc_mix_suggest()];
            $hist = array_map(fn($h) => ['seg' => $h['seg'], 'at' => $h['at'], 'by' => $h['by'], 'why' => $h['why']], array_reverse(pc_hist($pdo)));
            json_response(['unify' => json_decode((string)setting_get($pdo, 'price_unify_note', ''), true), 'auto' => $auto, 'hist' => $hist, 'charts' => $o, 'cust_col' => pc_cust_col($pdo), 'can_edit' => $canEdit, 'photo_url' => pc_photo_url($pdo)]);
        }
        case 'pc.suggest': { json_response(['rows' => pc_suggest_rows($seg)]); }
        case 'pc.combo': {   // 🧮 preview (apply = false) or put in (apply = true) the owner's combo lines at one column
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!empty($in['apply']) && !$canEdit) json_response(['error' => 'Only Admin / HR can change the price charts.'], 403);
            $r = pc_combo_plan($pdo, (string)($in['text'] ?? ''), (float)($in['base'] ?? 6), !empty($in['apply']), (string)($auth['full_name'] ?? $auth['username'] ?? ''));
            json_response($r, isset($r['error']) ? 422 : 200);
        }
        case 'pc.undo': {   // ↩ the section goes back to the version before its last change
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!$canEdit) json_response(['error' => 'Only Admin / HR can change the price charts.'], 403);
            $e = pc_undo($pdo, $seg); if ($e) json_response(['error' => $e], 422); if (function_exists('kb_touch')) kb_touch($pdo, 'Prices: undo'); json_response(['ok' => true, 'message' => 'Back to the version before the last change.']);
        }
        case 'pc.save': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!$canEdit) json_response(['error' => 'Only Admin / HR can change the price charts.'], 403);
            $byN = (string)($auth['full_name'] ?? $auth['username'] ?? ''); pc_hist_push($pdo, $seg, $byN, 'Saved on the Price charts page');
            if ($seg === 'mix') { [$C, $err] = pc_mix_clean((array)($in['chart'] ?? [])); if ($err) json_response(['error' => $err], 422); $C['updated_at'] = date('Y-m-d H:i:s'); setting_set($pdo, 'price_chart_mix', json_encode($C, JSON_UNESCAPED_UNICODE)); if (function_exists('kb_touch')) kb_touch($pdo, 'Prices: Combos');
                json_response(['ok' => true, 'chart' => pc_mix_get($pdo) + ['label' => 'Mix (combos)', 'unit' => 'mix', 'suggest' => pc_mix_suggest()], 'message' => 'Mix prices saved. The cart and the bot use them from now on.']); }
            [$C, $err] = pc_clean((array)($in['chart'] ?? [])); if ($err) json_response(['error' => $err], 422);
            $by = (string)($auth['full_name'] ?? $auth['username'] ?? ''); $e2 = pc_save($pdo, $seg, $C, $by); if ($e2) json_response(['error' => $e2], 422); if (function_exists('kb_touch')) kb_touch($pdo, 'Prices: ' . pc_segs()[$seg]);
            json_response(['ok' => true, 'chart' => pc_get($pdo, $seg) + ['label' => pc_segs()[$seg], 'unit' => pc_unit($seg), 'suggest' => pc_suggest_rows($seg)], 'message' => pc_segs()[$seg] . ' price chart saved. The cart and the bot use it from now on.']);
        }
        case 'pc.cust_col': {
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!$canEdit) json_response(['error' => 'Only Admin / HR can change this.'], 403);
            setting_set($pdo, 'price_chart_cust_col', (string)max(0, min(9, (int)($in['col'] ?? 0)))); if (function_exists('kb_touch')) kb_touch($pdo, 'Customer price photo column'); json_response(['ok' => true, 'cust_col' => pc_cust_col($pdo)]);
        }
        case 'pc.photo_save': {   // the page drew the customer photo (JPEG): a new token each time, the old file is removed
            if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); if (!$canEdit) json_response(['error' => 'Only Admin / HR can change this.'], 403);
            $bin = base64_decode(preg_replace('/^data:[^,]*,/', '', (string)($in['data'] ?? '')), true);
            if ($bin === false || strlen($bin) < 1000 || strlen($bin) > 6000000 || substr($bin, 0, 3) !== "\xFF\xD8\xFF") json_response(['error' => 'The photo could not be saved (not a JPEG).'], 422);
            $old = preg_replace('/[^a-f0-9]/', '', (string)setting_get($pdo, 'price_photo_token', '')); $t = bin2hex(random_bytes(16));
            if (@file_put_contents(pc_photo_dir() . $t . '.jpg', $bin) === false) json_response(['error' => 'The server could not store the photo (check the uploads folder).'], 500);
            setting_set($pdo, 'price_photo_token', $t); if ($old !== '' && $old !== $t) @unlink(pc_photo_dir() . $old . '.jpg');
            json_response(['ok' => true, 'url' => pc_photo_url($pdo)]);
        }
        default: json_response(['error' => 'Unknown price chart action.'], 404);
    }
}
