<?php
/**
 * wa_kflow.php — 🔑 KEY FLOWS: fixed answers for fixed messages (website pre-filled texts, ads, campaign buttons), built on a canvas. Admin only.
 *
 * A key flow = one graph. Node types:
 *   start     triggers [{m: exact|starts|contains|regex|button, v}] · next
 *   text      text · mode: buttons|url|none · buttons [{t, to}] (≤3, ≤20 letters) · url {t, u} · next (mode none)
 *   media     kind image|video|document · lib_id (Media library) · text (caption) · mode / buttons / url / next like text
 *   list      header · text · button · rows [{t, d, to}] (≤10)
 *   products  list_id (📋 Lists: live best sellers from Shopify) · mode / buttons / url / next like text
 *   template  tpl_id (approved pre-made message: works outside the 24 h window) · next
 *   handoff   text · alert (call-now) — the chat goes to the least busy salesperson present (if nobody has it)
 *   goto      flow_id (another key flow, from its start)
 *   tag       tag · next
 * Every node: id, type, x, y.
 *
 * WHO ANSWERS FIRST (wkf_on_in, called by wag_after_in before the old flows, greetings and the bot)
 *   1. opted out / complaint chat → nothing.   2. a tap on a key-flow button or list row (id kf.<flow>.<node>.<i>), even on an old message → its box.
 *   3. a person wrote to the customer in the last 30 minutes → nothing automatic.   4. start text matched (the flow highest in the list first) → that flow.
 *   Answered here = the old flows, greetings and the bot stay quiet for this message (the reply updates last_out_at).
 *   The same flow does not start twice for one customer within 90 seconds (double messages).
 * MATCHING: exact / starts / contains compare a loose form (capitals, spaces, punctuation and emoji ignored). regex runs on the text as written, case-insensitive.
 * SAVE: errors (red dot on the box) keep a flow off; two ON flows may not share the same exact text or button (conflict). Overlaps are shown with the winner.
 *
 *   wa.kf_list · wa.kf_get {id} · wa.kf_save {id?, name, graph, active?} · wa.kf_toggle {id, active} · wa.kf_order {ids} · wa.kf_copy {id} · wa.kf_delete {id}
 *   wa.kf_test {text} · wa.kf_conflicts · wa.kf_link {id} · wa.kf_assets (media library, approved templates, Lists, flows)
 * Loaded by wa_more.php (before wa_seg.php). PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wkf_tables')) {

function wkf_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_kflows (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 0, prio INT NOT NULL DEFAULT 0,
            graph MEDIUMTEXT NULL, created_by INT NOT NULL DEFAULT 0, created_at DATETIME NULL, updated_at DATETIME NULL, KEY k_act (active, prio)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_kflow_ev (id INT AUTO_INCREMENT PRIMARY KEY, flow_id INT NOT NULL, node_id VARCHAR(16) NOT NULL DEFAULT '', phone VARCHAR(12) NOT NULL,
            kind VARCHAR(8) NOT NULL, info VARCHAR(200) NOT NULL DEFAULT '', at DATETIME NOT NULL, KEY k_flow (flow_id, at), KEY k_phone (phone, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}
function wkf_types(): array { return ['start', 'text', 'media', 'list', 'products', 'template', 'handoff', 'goto', 'tag']; }
/** Loose form for exact / starts / contains: lower case, only letters and digits, single spaces. */
function wkf_loose(string $s): string
{
    $s = mb_strtolower(str_replace(["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], ["'", "'", '"', '"', '', '', '', ''], $s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s); return trim((string)preg_replace('/\s+/u', ' ', (string)$s));
}
function wkf_rx(string $p): string { return '~' . str_replace('~', '\~', $p) . '~iu'; }
function wkf_rx_ok(string $p): bool { if ($p === '' || mb_strlen($p) > 200) return false; return @preg_match(wkf_rx($p), '') !== false; }
/** Does one trigger catch this message? $text = typed text, $btn = title of a tapped template / old button ('' if typed). */
function wkf_trig_hit(array $t, string $text, string $btn): bool
{
    $m = (string)($t['m'] ?? ''); $v = (string)($t['v'] ?? ''); if ($v === '') return false;
    if ($m === 'button') return $btn !== '' && wkf_loose($btn) === wkf_loose($v);
    $raw = $btn !== '' ? $btn : $text; if (trim($raw) === '') return false;
    if ($m === 'regex') return wkf_rx_ok($v) && preg_match(wkf_rx($v), trim($raw)) === 1;
    $a = wkf_loose($raw); $b = wkf_loose($v); if ($b === '') return false;
    if ($m === 'exact') return $a === $b;
    if ($m === 'starts') return $a === $b || mb_strpos($a, $b . ' ') === 0;
    if ($m === 'contains') return (bool)preg_match('/(^| )' . preg_quote($b, '/') . '( |$)/u', $a);   // whole words, so "price" does not catch "priceless"
    return false;
}
function wkf_graph($raw): array
{
    $g = is_array($raw) ? $raw : json_decode((string)$raw, true); if (!is_array($g)) $g = [];
    $nodes = []; foreach ((array)($g['nodes'] ?? []) as $n) { if (!is_array($n)) continue; $id = preg_replace('/[^a-z0-9_]/i', '', (string)($n['id'] ?? '')); $ty = (string)($n['type'] ?? ''); if ($id === '' || !in_array($ty, wkf_types(), true)) continue; $n['id'] = mb_substr($id, 0, 16); $n['x'] = (int)($n['x'] ?? 0); $n['y'] = (int)($n['y'] ?? 0); $nodes[] = $n; }
    if (!array_filter($nodes, function ($n) { return $n['type'] === 'start'; })) array_unshift($nodes, ['id' => 'start', 'type' => 'start', 'x' => 80, 'y' => 120, 'triggers' => [], 'next' => '']);
    return ['nodes' => array_slice($nodes, 0, 80)];
}
function wkf_node(array $g, string $id): ?array { foreach ($g['nodes'] as $n) if ($n['id'] === $id) return $n; return null; }
function wkf_start(array $g): array { foreach ($g['nodes'] as $n) if ($n['type'] === 'start') return $n; return ['id' => 'start', 'type' => 'start', 'triggers' => [], 'next' => '']; }
function wkf_row(PDO $pdo, int $id): ?array { $q = $pdo->prepare("SELECT * FROM wa_kflows WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); return $r ?: null; }
function wkf_active(PDO $pdo): array { static $c = null; if ($c !== null) return $c; $c = []; foreach ($pdo->query("SELECT * FROM wa_kflows WHERE active = 1 ORDER BY prio, id")->fetchAll() as $r) { $r['g'] = wkf_graph($r['graph']); $c[] = $r; } return $c; }

/** Errors that keep the flow OFF: [nodeId => [messages]]. $flows = id => name of the other flows (for go-to). */
function wkf_validate(PDO $pdo, array $g, int $selfId = 0): array
{
    $E = []; $ids = array_column($g['nodes'], 'id'); $bad = function ($id, $m) use (&$E) { $E[$id][] = $m; };
    $to = function ($n, $v, $what) use ($ids, $bad) { if ((string)$v === '') $bad($n['id'], $what . ' goes nowhere — connect it to a box.'); elseif (!in_array((string)$v, $ids, true)) $bad($n['id'], $what . ' points to a box that was deleted.'); };
    $opt = function ($n) use ($ids, $bad) { $v = (string)($n['next'] ?? ''); if ($v !== '' && !in_array($v, $ids, true)) $bad($n['id'], 'The next box was deleted.'); };
    $mode = function ($n) use ($to, $opt, $bad) {
        $md = (string)($n['mode'] ?? 'none');
        if ($md === 'buttons') { $b = array_values((array)($n['buttons'] ?? [])); if (!$b) $bad($n['id'], 'Add at least one button, or choose “None”.'); if (count($b) > 3) $bad($n['id'], 'WhatsApp allows 3 buttons — use a List for more.');
            $seen = []; foreach ($b as $i => $x) { $t = trim((string)($x['t'] ?? '')); if ($t === '') $bad($n['id'], 'Button ' . ($i + 1) . ' has no text.'); elseif (mb_strlen($t) > 20) $bad($n['id'], '“' . $t . '” is longer than 20 letters.'); if (isset($seen[mb_strtolower($t)])) $bad($n['id'], 'Two buttons have the same text.'); $seen[mb_strtolower($t)] = 1; $to($n, $x['to'] ?? '', 'Button “' . $t . '”'); }
            if (trim((string)($n['text'] ?? '')) === '') $bad($n['id'], 'A message with buttons needs text.'); }
        elseif ($md === 'url') { $u = (array)($n['url'] ?? []); $t = trim((string)($u['t'] ?? '')); if ($t === '' || mb_strlen($t) > 20) $bad($n['id'], 'The website button needs a text of 1–20 letters.'); if (!preg_match('~^https://[^\s]+\.[^\s]+$~i', (string)($u['u'] ?? ''))) $bad($n['id'], 'The website link must start with https://'); if (trim((string)($n['text'] ?? '')) === '') $bad($n['id'], 'A message with a website button needs text.'); $opt($n); }
        else $opt($n);
    };
    foreach ($g['nodes'] as $n) {
        $ty = $n['type'];
        if ($ty === 'start') {
            $tr = array_values(array_filter((array)($n['triggers'] ?? []), function ($t) { return trim((string)($t['v'] ?? '')) !== ''; }));
            if (!$tr) $bad($n['id'], 'Add at least one start text.');
            foreach ($tr as $t) { if (!in_array($t['m'] ?? '', ['exact', 'starts', 'contains', 'regex', 'button'], true)) $bad($n['id'], 'Unknown match type.'); elseif ($t['m'] === 'regex' && !wkf_rx_ok((string)$t['v'])) $bad($n['id'], 'The regex “' . mb_substr((string)$t['v'], 0, 40) . '” is not valid.'); elseif ($t['m'] !== 'regex' && wkf_loose((string)$t['v']) === '') $bad($n['id'], '“' . $t['v'] . '” has no letters or numbers.'); }
            $to($n, $n['next'] ?? '', 'Start');
        } elseif ($ty === 'text') { if (trim((string)($n['text'] ?? '')) === '') $bad($n['id'], 'Type the message.'); if (mb_strlen((string)($n['text'] ?? '')) > 1024) $bad($n['id'], 'The text is longer than 1,024 letters.'); $mode($n); }
        elseif ($ty === 'media') {
            $k = (string)($n['kind'] ?? ''); $lr = (int)($n['lib_id'] ?? 0) > 0 && function_exists('wa_lib_row') ? wa_lib_row($pdo, (int)$n['lib_id']) : null;
            if (!$lr) $bad($n['id'], 'Choose a photo, video or PDF from the Media library.'); elseif (!in_array((string)$lr['kind'], ['image', 'video', 'document'], true)) $bad($n['id'], 'This media type cannot be sent here.');
            if (mb_strlen((string)($n['text'] ?? '')) > 1024) $bad($n['id'], 'The text is longer than 1,024 letters.'); $mode($n);
        } elseif ($ty === 'products') { if ((int)($n['list_id'] ?? 0) <= 0) $bad($n['id'], 'Choose a list from 📋 Lists.'); $mode($n); }
        elseif ($ty === 'list') {
            $rows = array_values((array)($n['rows'] ?? [])); if (!$rows) $bad($n['id'], 'Add at least one row.'); if (count($rows) > 10) $bad($n['id'], 'A list has at most 10 rows.');
            if (trim((string)($n['text'] ?? '')) === '') $bad($n['id'], 'Type the message above the list.'); $bt = trim((string)($n['button'] ?? '')); if ($bt === '' || mb_strlen($bt) > 20) $bad($n['id'], 'The list button needs a text of 1–20 letters.');
            if (mb_strlen((string)($n['header'] ?? '')) > 60) $bad($n['id'], 'The title is longer than 60 letters.');
            foreach ($rows as $i => $r) { $t = trim((string)($r['t'] ?? '')); if ($t === '') $bad($n['id'], 'Row ' . ($i + 1) . ' has no title.'); elseif (mb_strlen($t) > 24) $bad($n['id'], 'Row “' . $t . '” is longer than 24 letters.'); if (mb_strlen((string)($r['d'] ?? '')) > 72) $bad($n['id'], 'The description of row ' . ($i + 1) . ' is longer than 72 letters.'); $to($n, $r['to'] ?? '', 'Row “' . $t . '”'); }
        } elseif ($ty === 'template') { if ((int)($n['tpl_id'] ?? 0) <= 0) $bad($n['id'], 'Choose an approved pre-made message.'); $opt($n); }
        elseif ($ty === 'handoff') { if (mb_strlen((string)($n['text'] ?? '')) > 1024) $bad($n['id'], 'The text is longer than 1,024 letters.'); }
        elseif ($ty === 'goto') { $f = (int)($n['flow_id'] ?? 0); if ($f <= 0) $bad($n['id'], 'Choose the flow to go to.'); elseif ($f === $selfId) $bad($n['id'], 'A flow cannot go to itself.'); }
        elseif ($ty === 'tag') { if (trim((string)($n['tag'] ?? '')) === '') $bad($n['id'], 'Type the tag.'); $opt($n); }
    }
    return $E;
}
/** Overlaps between flows. $mine = [id, name, prio, triggers] of the flow being saved (or null = all active vs all active). Returns [hard[], soft[]]. */
function wkf_conflicts(PDO $pdo, ?array $mine): array
{
    $all = []; foreach ($pdo->query("SELECT id, name, prio, active, graph FROM wa_kflows ORDER BY prio, id")->fetchAll() as $r) { if ($mine && (int)$r['id'] === (int)$mine['id']) continue; if (!(int)$r['active']) continue; $all[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'prio' => (int)$r['prio'], 'tr' => (array)(wkf_start(wkf_graph($r['graph']))['triggers'] ?? [])]; }
    $pairs = $mine ? array_map(function ($o) use ($mine) { return [$mine, $o]; }, $all) : [];
    if (!$mine) for ($i = 0; $i < count($all); $i++) for ($j = $i + 1; $j < count($all); $j++) $pairs[] = [$all[$i], $all[$j]];
    $hard = []; $soft = []; $seen = [];
    foreach ($pairs as [$A, $B]) {
        $win = ($A['prio'] < $B['prio'] || ($A['prio'] === $B['prio'] && $A['id'] < $B['id'])) ? $A : $B;
        foreach ((array)$A['tr'] as $ta) foreach ((array)$B['tr'] as $tb) {
            $va = (string)($ta['v'] ?? ''); $vb = (string)($tb['v'] ?? ''); if ($va === '' || $vb === '') continue;
            $ma = (string)($ta['m'] ?? ''); $mb = (string)($tb['m'] ?? '');
            if ($ma === $mb && $ma !== 'regex' && wkf_loose($va) === wkf_loose($vb) || ($ma === 'regex' && $mb === 'regex' && $va === $vb)) { $hard[] = ['text' => $va, 'a' => $A['name'], 'b' => $B['name'], 'm' => $ma]; continue; }
            foreach ([[$ta, $tb], [$tb, $ta]] as [$x, $y]) {   // a sample of x (its own words) caught by y
                if (($x['m'] ?? '') === 'regex') continue; $sample = (string)$x['v']; $btn = ($x['m'] ?? '') === 'button' ? $sample : '';
                if (wkf_trig_hit($y, $btn !== '' ? '' : $sample, $btn)) { $k = wkf_loose($sample) . '|' . $A['id'] . '|' . $B['id']; if (isset($seen[$k])) continue; $seen[$k] = 1;
                    $soft[] = ['text' => $sample, 'a' => $A['name'], 'b' => $B['name'], 'winner' => $win['name']]; }
            }
        }
    }
    return [$hard, $soft];
}
function wkf_ev(PDO $pdo, int $flow, string $node, string $phone, string $kind, string $info = ''): void
{ try { $pdo->prepare("INSERT INTO wa_kflow_ev (flow_id, node_id, phone, kind, info, at) VALUES (?, ?, ?, ?, ?, ?)")->execute([$flow, mb_substr($node, 0, 16), $phone, $kind, mb_substr($info, 0, 200), date('Y-m-d H:i:s')]); } catch (Throwable $e) { } }
function wkf_fill(string $t, array $c): string { return str_replace(['{name}', '{{1}}'], function_exists('wa_first_name') ? wa_first_name((string)$c['name']) : (string)$c['name'], $t); }

// ---------------------------------------------------------------------------------------------------------------- sending
/** The interactive "action" part for a node's buttons / website button. */
function wkf_action(array $n, int $fid, string $text): ?array
{
    $md = (string)($n['mode'] ?? 'none');
    if ($md === 'buttons') { $bt = []; foreach (array_slice(array_values((array)($n['buttons'] ?? [])), 0, 3) as $i => $b) $bt[] = ['type' => 'reply', 'reply' => ['id' => 'kf.' . $fid . '.' . $n['id'] . '.' . $i, 'title' => mb_substr(trim((string)$b['t']), 0, 20)]];
        return ['type' => 'button', 'body' => ['text' => mb_substr($text, 0, 1024)], 'action' => ['buttons' => $bt]]; }
    if ($md === 'url') { $u = (array)($n['url'] ?? []); return ['type' => 'cta_url', 'body' => ['text' => mb_substr($text, 0, 1024)], 'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => mb_substr(trim((string)$u['t']), 0, 20), 'url' => (string)$u['u']]]]; }
    return null;
}
function wkf_shown(array $n, string $text): string
{
    $md = (string)($n['mode'] ?? 'none');
    if ($md === 'buttons') return $text . "\n[" . implode('] [', array_map(function ($b) { return (string)$b['t']; }, (array)($n['buttons'] ?? []))) . ']';
    if ($md === 'url') return $text . "\n[🌐 " . ((array)($n['url'] ?? []))['t'] . ' → ' . ((array)($n['url'] ?? []))['u'] . ']';
    return $text;
}
/** Sends one box. Returns [ok, error]. */
function wkf_send_node(PDO $pdo, array $c, array $n, int $fid): array
{
    $ty = $n['type'];
    if ($ty === 'text' || $ty === 'products') {
        if ($ty === 'products') { if (!function_exists('wl_get')) return [false, 'Lists are not available.']; $l = wl_get($pdo, (int)($n['list_id'] ?? 0)); if (!$l) return [false, 'The list was deleted.']; $txt = wl_text($pdo, $l, (string)$c['name']); if (trim((string)($n['text'] ?? '')) !== '') $txt = wkf_fill((string)$n['text'], $c) . "\n\n" . $txt; }
        else $txt = wkf_fill((string)$n['text'], $c);
        $a = wkf_action($n, $fid, $txt);
        if (!$a) { $r = wa_send_text($pdo, $c, mb_substr($txt, 0, 4000), 'flow', 0); return [(bool)$r[0], $r[0] ? '' : (string)$r[1]]; }
        $r = wag_flow_send_interactive($pdo, $c, $a, wkf_shown($n, $txt)); return [(bool)$r[0], $r[0] ? '' : (string)$r[1]];
    }
    if ($ty === 'media') {
        $lr = wa_lib_row($pdo, (int)($n['lib_id'] ?? 0)); if (!$lr) return [false, 'The media was deleted from the library.'];
        $pid = wag_flow_pid($pdo, $c); $kind = (string)$lr['kind']; [$mid, $e] = wa_lib_media_id($pdo, $lr, $pid); if ($mid === '') return [false, (string)$e];
        $txt = wkf_fill((string)($n['text'] ?? ''), $c); $a = wkf_action($n, $fid, $txt !== '' ? $txt : ' ');
        if (!$a) { $r = wa_send_media($pdo, $c, $kind, $mid, (string)$lr['mime'], $txt, (string)$lr['filename'], 0); return [(bool)$r[0], $r[0] ? '' : (string)$r[1]]; }
        $h = ['type' => $kind, $kind => ['id' => $mid]]; if ($kind === 'document') $h['document']['filename'] = (string)($lr['filename'] ?: 'file.pdf');
        $a['header'] = $h; $shown = ($kind === 'video' ? '🎬 ' : ($kind === 'document' ? '📄 ' : '📷 ')) . wkf_shown($n, $txt);
        $r = wag_flow_send_interactive($pdo, $c, $a, $shown, $mid, (string)$lr['mime']);
        if (!$r[0] && (in_array((int)($r[2] ?? 0), [100, 131052, 131053, 131009], true) || stripos((string)$r[1], 'media') !== false)) { [$mid2] = wa_lib_media_id($pdo, $lr, $pid, true); if ($mid2 !== '') { $a['header'][$kind]['id'] = $mid2; $r = wag_flow_send_interactive($pdo, $c, $a, $shown, $mid2, (string)$lr['mime']); } }
        return [(bool)$r[0], $r[0] ? '' : (string)$r[1]];
    }
    if ($ty === 'list') {
        $rows = []; foreach (array_slice(array_values((array)($n['rows'] ?? [])), 0, 10) as $i => $x) { $o = ['id' => 'kf.' . $fid . '.' . $n['id'] . '.r' . $i, 'title' => mb_substr(trim((string)$x['t']), 0, 24)]; if (trim((string)($x['d'] ?? '')) !== '') $o['description'] = mb_substr(trim((string)$x['d']), 0, 72); $rows[] = $o; }
        $txt = wkf_fill((string)$n['text'], $c); $i = ['type' => 'list']; if (trim((string)($n['header'] ?? '')) !== '') $i['header'] = ['type' => 'text', 'text' => mb_substr(wkf_fill((string)$n['header'], $c), 0, 60)];
        $i['body'] = ['text' => mb_substr($txt, 0, 1024)]; $i['action'] = ['button' => mb_substr((string)$n['button'], 0, 20), 'sections' => [['title' => 'Options', 'rows' => $rows]]];
        $r = wag_flow_send_interactive($pdo, $c, $i, '📋 ' . $txt . "\n[" . implode('] [', array_column($rows, 'title')) . ']'); return [(bool)$r[0], $r[0] ? '' : (string)$r[1]];
    }
    if ($ty === 'template') {
        if (!function_exists('wa_camp_tpl')) return [false, 'Pre-made messages are not available.'];
        $t = wa_camp_tpl($pdo, (int)($n['tpl_id'] ?? 0)); if (!$t) return [false, 'The pre-made message was not found or is not approved.'];
        $ctx = wa_camp_ctx($pdo, $t, 2); [$nid, $why] = wa_pick_number($pdo, $c, $ctx); if ($nid === 0) return [false, 'No number can send now: ' . $why];
        $r = wa_send_tpl_one($pdo, $c, $t, $ctx['nums'][$nid], wa_tpl_vars_for($t, (string)$c['name'], []), 'flow'); return [(bool)$r[0], $r[0] ? '' : (string)$r[1]];
    }
    if ($ty === 'handoff') {
        $ok = true; $err = ''; $txt = trim(wkf_fill((string)($n['text'] ?? ''), $c));
        if ($txt !== '') { $r = wa_send_text($pdo, $c, $txt, 'flow', 0); $ok = (bool)$r[0]; $err = $ok ? '' : (string)$r[1]; }
        $key = (string)$c['phone']; $emp = (int)$c['assigned_emp'];
        if ($emp <= 0 && function_exists('wa_people')) { foreach (wa_people($pdo, (int)$c['last_number_id']) as $p) { if (!empty($p['present']) && empty($p['full'])) { $emp = (int)$p['emp_id']; break; } } if ($emp > 0) wa_assign_to($pdo, $key, $emp, 0, 'flow'); }
        else $pdo->prepare("UPDATE wa_contacts SET state = 'human', status = 'open', closed_at = NULL WHERE phone = ?")->execute([$key]);
        if (!empty($n['alert'])) { try { $pdo->prepare("UPDATE wa_contacts SET callnow = 1, callnow_at = ? WHERE phone = ? AND callnow NOT IN (1, 2)")->execute([date('Y-m-d H:i:s'), $key]); } catch (Throwable $e) { } }
        if ($emp > 0 && function_exists('ct_push')) { try { ct_push($pdo, [$emp], 'Customer wants a person', ($c['name'] !== '' ? $c['name'] : '+91 ' . $key) . ' asked to talk to someone (key flow).', '/my_chats.html?phone=' . $key, 'wa-kf-' . $key); } catch (Throwable $e) { } }
        if (function_exists('wa_event')) { try { wa_event($pdo, $key, '🔑 Key flow handed the chat to ' . ($emp > 0 ? (employee_names_by_id($pdo)[$emp] ?? 'a salesperson') : 'the team (nobody free — it waits in Waiting)')); } catch (Throwable $e) { } }
        return [$ok, $err];
    }
    if ($ty === 'tag') { $tag = mb_substr(preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower(trim((string)$n['tag']))), 0, 40); if ($tag !== '') { try { $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES (?, ?, 'flow', ?)")->execute([(string)$c['phone'], $tag, date('Y-m-d H:i:s')]); $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES (?, ?)")->execute([(string)$c['phone'], $tag]); } catch (Throwable $e) { } } return [true, '']; }
    return [true, ''];
}
/** Runs boxes from $nodeId on: sends each, follows "next" until a box waits for a tap (buttons / list), ends, or 8 boxes (no loops). */
function wkf_run(PDO $pdo, array $c, array $flow, string $nodeId, int $hops = 0): bool
{
    $g = $flow['g'] ?? wkf_graph($flow['graph']); $fid = (int)$flow['id']; $key = (string)$c['phone']; $sent = false; $seen = [];
    for ($i = 0; $i < 8 && $nodeId !== ''; $i++) {
        if (isset($seen[$nodeId])) break; $seen[$nodeId] = 1; $n = wkf_node($g, $nodeId); if (!$n || $n['type'] === 'start') break;
        if ($n['type'] === 'goto') {
            if ($hops >= 2) break; $to = wkf_row($pdo, (int)($n['flow_id'] ?? 0)); if (!$to || !(int)$to['active']) break; $to['g'] = wkf_graph($to['graph']);
            wkf_ev($pdo, $fid, $n['id'], $key, 'sent', 'go to flow ' . $to['id']); wkf_ev($pdo, (int)$to['id'], 'start', $key, 'start', 'from flow ' . $fid);
            return wkf_run($pdo, $c, $to, (string)(wkf_start($to['g'])['next'] ?? ''), $hops + 1) || $sent;
        }
        [$ok, $err] = wkf_send_node($pdo, $c, $n, $fid); $c = wa_contact($pdo, $key) ?: $c;
        wkf_ev($pdo, $fid, $n['id'], $key, $ok ? 'sent' : 'err', $err);
        if (!$ok) { if (function_exists('wa_log')) wa_log($pdo, 'engine', false, 'key flow ' . $fid . ' · ' . $key . ' · ' . $err); break; }
        $sent = true;
        if ($n['type'] === 'list' || ($n['type'] === 'handoff') || in_array((string)($n['mode'] ?? ''), ['buttons'], true) && in_array($n['type'], ['text', 'media', 'products'], true)) break;   // waits for a tap (or a person takes over)
        $nodeId = (string)($n['next'] ?? '');
    }
    return $sent;
}
/** A customer's message. Returns true when a key flow answered it (the old flows, greetings and the bot then stay quiet). */
function wkf_on_in(PDO $pdo, string $key, array $m, bool $isNew = false, array $ref = []): bool
{
    if (!wkf_tables($pdo)) return false; $c = wa_contact($pdo, $key); if (!$c || (int)$c['optout'] === 1 || !empty($c['cx_id'])) return false;
    $iid = (string)($m['interactive']['button_reply']['id'] ?? $m['interactive']['list_reply']['id'] ?? '');
    if (preg_match('/^kf\.(\d+)\.([a-z0-9_]+)\.(r?)(\d+)$/i', $iid, $x)) {   // a tap on a key-flow button / list row — works on old messages too
        $f = wkf_row($pdo, (int)$x[1]); if (!$f || !(int)$f['active']) return false; $f['g'] = wkf_graph($f['graph']); $n = wkf_node($f['g'], $x[2]); if (!$n) return false;
        $list = array_values((array)($x[3] === 'r' ? ($n['rows'] ?? []) : ($n['buttons'] ?? []))); $to = (string)(($list[(int)$x[4]] ?? [])['to'] ?? '');
        wkf_ev($pdo, (int)$f['id'], $n['id'], $key, 'tap', $x[3] . $x[4]); if ($to === '') return true;
        wkf_run($pdo, $c, $f, $to); return true;
    }
    $btn = trim((string)($m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''));
    $text = trim((string)($m['text']['body'] ?? '')); if ($text === '' && $btn === '') return false;
    // a person is talking to this customer: no automatic answer
    try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE phone = ? AND dir = 'out' AND emp_id > 0 AND ts >= ?"); $q->execute([$key, date('Y-m-d H:i:s', time() - 1800)]); if ((int)$q->fetchColumn() > 0) return false; } catch (Throwable $e) { }
    foreach (wkf_active($pdo) as $f) {
        $st = wkf_start($f['g']); $hit = false; foreach ((array)($st['triggers'] ?? []) as $t) if (wkf_trig_hit((array)$t, $text, $btn)) { $hit = true; break; }
        if (!$hit) continue;
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_kflow_ev WHERE flow_id = ? AND phone = ? AND kind = 'start' AND at >= ?"); $q->execute([(int)$f['id'], $key, date('Y-m-d H:i:s', time() - 90)]); if ((int)$q->fetchColumn() > 0) return true;   // the same text twice in a row: answered once
        wkf_ev($pdo, (int)$f['id'], 'start', $key, 'start', mb_substr($btn !== '' ? $btn : $text, 0, 200));
        wkf_run($pdo, $c, $f, (string)($st['next'] ?? '')); return true;
    }
    return false;
}
/** Which flow answers this text (the 🧪 Test box). */
function wkf_test(PDO $pdo, string $text): array
{
    $out = []; foreach ($pdo->query("SELECT * FROM wa_kflows ORDER BY prio, id")->fetchAll() as $f) { $st = wkf_start(wkf_graph($f['graph'])); foreach ((array)($st['triggers'] ?? []) as $t) if (wkf_trig_hit((array)$t, $text, '') || (($t['m'] ?? '') === 'button' && wkf_trig_hit((array)$t, '', $text))) { $out[] = ['id' => (int)$f['id'], 'name' => (string)$f['name'], 'active' => (int)$f['active'] === 1, 'm' => (string)$t['m'], 'v' => (string)$t['v']]; break; } }
    $win = null; foreach ($out as $o) if ($o['active']) { $win = $o; break; }
    return ['winner' => $win, 'all' => $out];
}
function wkf_stats(PDO $pdo, int $days = 7): array
{
    $o = []; $since = date('Y-m-d H:i:s', time() - $days * 86400);
    try { $q = $pdo->prepare("SELECT flow_id, SUM(kind = 'start') AS st, SUM(kind = 'tap') AS tp, COUNT(DISTINCT CASE WHEN kind = 'start' THEN phone END) AS ppl, COUNT(DISTINCT CASE WHEN kind = 'tap' THEN phone END) AS tpp, SUM(kind = 'err') AS er FROM wa_kflow_ev WHERE at >= ? GROUP BY flow_id"); $q->execute([$since]);
        foreach ($q->fetchAll() as $r) $o[(int)$r['flow_id']] = ['started' => (int)$r['st'], 'taps' => (int)$r['tp'], 'people' => (int)$r['ppl'], 'tapped_pct' => (int)$r['ppl'] ? (int)round((int)$r['tpp'] * 100 / (int)$r['ppl']) : 0, 'errors' => (int)$r['er'], 'orders' => 0]; } catch (Throwable $e) { }
    try { $q = $pdo->prepare("SELECT e.flow_id, COUNT(DISTINCT e.phone) AS n FROM (SELECT flow_id, phone, MIN(at) AS at FROM wa_kflow_ev WHERE kind = 'start' AND at >= ? GROUP BY flow_id, phone) e JOIN wa_cust s ON s.phone = e.phone AND s.last_order > e.at GROUP BY e.flow_id"); $q->execute([$since]);
        foreach ($q->fetchAll() as $r) if (isset($o[(int)$r['flow_id']])) $o[(int)$r['flow_id']]['orders'] = (int)$r['n']; } catch (Throwable $e) { }
    return $o;
}
function wkf_public(PDO $pdo, array $r, array $stats = [], bool $deep = false): array
{
    $g = wkf_graph($r['graph']); $E = wkf_validate($pdo, $g, (int)$r['id']); $st = wkf_start($g);
    $o = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'active' => (int)$r['active'] === 1, 'prio' => (int)$r['prio'], 'triggers' => array_values((array)($st['triggers'] ?? [])), 'boxes' => count($g['nodes']) - 1,
        'errors' => array_sum(array_map('count', $E)), 'stats' => $stats[(int)$r['id']] ?? ['started' => 0, 'taps' => 0, 'people' => 0, 'tapped_pct' => 0, 'errors' => 0, 'orders' => 0], 'updated_at' => (string)$r['updated_at']];
    if ($deep) { $o['graph'] = $g; $o['problems'] = $E; }
    return $o;
}
function wkf_node_stats(PDO $pdo, int $id, int $days = 30): array
{
    $o = []; try { $q = $pdo->prepare("SELECT node_id, kind, info, COUNT(*) AS n FROM wa_kflow_ev WHERE flow_id = ? AND at >= ? GROUP BY node_id, kind, info"); $q->execute([$id, date('Y-m-d H:i:s', time() - $days * 86400)]);
        foreach ($q->fetchAll() as $r) { $nid = (string)$r['node_id']; $o[$nid] = $o[$nid] ?? ['sent' => 0, 'err' => 0, 'taps' => [], 'start' => 0, 'last_err' => '']; if ($r['kind'] === 'tap') $o[$nid]['taps'][(string)$r['info']] = ($o[$nid]['taps'][(string)$r['info']] ?? 0) + (int)$r['n']; elseif ($r['kind'] === 'err') { $o[$nid]['err'] += (int)$r['n']; $o[$nid]['last_err'] = (string)$r['info']; } elseif (isset($o[$nid][$r['kind']])) $o[$nid][$r['kind']] += (int)$r['n']; } } catch (Throwable $e) { }
    return $o;
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- router
$__wkf = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wkf, 'wa.kf_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !wkf_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    $me = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : []; $now = date('Y-m-d H:i:s');
    $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $get = function (int $id) use ($pdo) { $r = wkf_row($pdo, $id); if (!$r) json_response(['error' => 'Flow not found.'], 404); return $r; };
    $check = function (array $r, array $g) use ($pdo) { $E = wkf_validate($pdo, $g, (int)$r['id']); [$hard, $soft] = wkf_conflicts($pdo, ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'prio' => (int)$r['prio'], 'tr' => (array)(wkf_start($g)['triggers'] ?? [])]); return [$E, $hard, $soft]; };
    switch ($__wkf) {
        case 'wa.kf_list': { $st = wkf_stats($pdo, 7); $rows = $pdo->query("SELECT * FROM wa_kflows ORDER BY prio, id")->fetchAll(); [, $soft] = wkf_conflicts($pdo, null);
            json_response(['ok' => true, 'flows' => array_map(function ($r) use ($pdo, $st) { return wkf_public($pdo, $r, $st); }, $rows), 'overlaps' => $soft]); }
        case 'wa.kf_get': { $r = $get((int)($_GET['id'] ?? 0)); $o = wkf_public($pdo, $r, wkf_stats($pdo, 30), true); [, $hard, $soft] = $check($r, $o['graph']);
            json_response(['ok' => true, 'flow' => $o, 'node_stats' => wkf_node_stats($pdo, (int)$r['id']), 'hard' => $hard, 'soft' => $soft]); }
        case 'wa.kf_save': {
            $needPost(); $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80)); if ($name === '') json_response(['error' => 'Give the flow a name.'], 422);
            $g = wkf_graph($in['graph'] ?? []); $id = (int)($in['id'] ?? 0); $want = !empty($in['active']);
            if ($id > 0) { $r = $get($id); $pdo->prepare("UPDATE wa_kflows SET name = ?, graph = ?, updated_at = ? WHERE id = ?")->execute([$name, json_encode($g, JSON_UNESCAPED_UNICODE), $now, $id]); }
            else { $p = (int)$pdo->query("SELECT COALESCE(MAX(prio), 0) + 1 FROM wa_kflows")->fetchColumn(); $pdo->prepare("INSERT INTO wa_kflows (name, active, prio, graph, created_by, created_at, updated_at) VALUES (?, 0, ?, ?, ?, ?, ?)")->execute([$name, $p, json_encode($g, JSON_UNESCAPED_UNICODE), $me, $now, $now]); $id = (int)$pdo->lastInsertId(); }
            $r = $get($id); [$E, $hard, $soft] = $check($r, $g); $on = $want && !$E && !$hard; $pdo->prepare("UPDATE wa_kflows SET active = ? WHERE id = ?")->execute([$on ? 1 : 0, $id]);
            $msg = $want && !$on ? ($E ? 'Saved, but kept OFF — fix the boxes with a red dot first.' : 'Saved, but kept OFF — another ON flow already uses the same start text.') : 'Saved';
            json_response(['ok' => true, 'id' => $id, 'active' => $on, 'message' => $msg, 'problems' => $E, 'hard' => $hard, 'soft' => $soft]);
        }
        case 'wa.kf_toggle': {
            $needPost(); $r = $get((int)($in['id'] ?? 0)); $on = !empty($in['active']);
            if ($on) { $g = wkf_graph($r['graph']); [$E, $hard] = $check($r, $g); if ($E) json_response(['error' => 'Fix the boxes with a red dot first (' . array_sum(array_map('count', $E)) . ' problem' . (array_sum(array_map('count', $E)) === 1 ? '' : 's') . ').', 'problems' => $E], 422);
                if ($hard) json_response(['error' => 'Another ON flow already starts with “' . $hard[0]['text'] . '” (' . $hard[0]['b'] . '). Change one of them first.', 'hard' => $hard], 422); }
            $pdo->prepare("UPDATE wa_kflows SET active = ?, updated_at = ? WHERE id = ?")->execute([$on ? 1 : 0, $now, (int)$r['id']]); json_response(['ok' => true, 'active' => $on]);
        }
        case 'wa.kf_order': { $needPost(); $u = $pdo->prepare("UPDATE wa_kflows SET prio = ? WHERE id = ?"); foreach (array_values((array)($in['ids'] ?? [])) as $i => $fid) $u->execute([$i + 1, (int)$fid]); json_response(['ok' => true]); }
        case 'wa.kf_copy': { $needPost(); $r = $get((int)($in['id'] ?? 0)); $p = (int)$pdo->query("SELECT COALESCE(MAX(prio), 0) + 1 FROM wa_kflows")->fetchColumn();
            $pdo->prepare("INSERT INTO wa_kflows (name, active, prio, graph, created_by, created_at, updated_at) VALUES (?, 0, ?, ?, ?, ?, ?)")->execute([mb_substr($r['name'] . ' (copy)', 0, 80), $p, $r['graph'], $me, $now, $now]); json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]); }
        case 'wa.kf_delete': { $needPost(); $r = $get((int)($in['id'] ?? 0)); $pdo->prepare("DELETE FROM wa_kflows WHERE id = ?")->execute([(int)$r['id']]); json_response(['ok' => true]); }
        case 'wa.kf_test': { $t = trim(mb_substr((string)($_GET['text'] ?? ''), 0, 500)); json_response(['ok' => true] + wkf_test($pdo, $t)); }
        case 'wa.kf_link': {
            $r = $get((int)($_GET['id'] ?? 0)); $tr = array_values(array_filter((array)(wkf_start(wkf_graph($r['graph']))['triggers'] ?? []), function ($t) { return in_array($t['m'] ?? '', ['exact', 'starts'], true) && trim((string)$t['v']) !== ''; }));
            $text = $tr ? (string)$tr[0]['v'] : ''; $links = [];
            foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll() as $n) { $d = preg_replace('/\D/', '', (string)$n['display']); if (strlen($d) === 10) $d = '91' . $d; if (strlen($d) < 11) continue; $links[] = ['label' => (string)($n['label'] ?: $n['display']), 'url' => 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '')]; }
            json_response(['ok' => true, 'text' => $text, 'links' => $links]);
        }
        case 'wa.kf_assets': {
            $lib = []; try { foreach ($pdo->query("SELECT id, title, kind, filename FROM wa_media_lib WHERE kind IN ('image', 'video', 'document') ORDER BY id DESC LIMIT 400")->fetchAll() as $x) $lib[] = ['id' => (int)$x['id'], 'title' => (string)$x['title'], 'kind' => (string)$x['kind'], 'file' => (string)$x['filename']]; } catch (Throwable $e) { }
            $tpl = []; try { foreach ($pdo->query("SELECT t.id, t.name, t.category, t.body FROM wa_templates t WHERE EXISTS (SELECT 1 FROM wa_tpl_waba w WHERE w.tpl_id = t.id AND w.status = 'APPROVED') ORDER BY t.name")->fetchAll() as $x) $tpl[] = ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'category' => (string)$x['category'], 'body' => mb_substr((string)$x['body'], 0, 160)]; } catch (Throwable $e) { }
            $lists = []; try { foreach ($pdo->query("SELECT id, name, grp FROM wa_lists ORDER BY grp, name")->fetchAll() as $x) $lists[] = ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'grp' => (string)$x['grp']]; } catch (Throwable $e) { }
            $fl = array_map(function ($x) { return ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'active' => (int)$x['active'] === 1]; }, $pdo->query("SELECT id, name, active FROM wa_kflows ORDER BY prio, id")->fetchAll());
            json_response(['ok' => true, 'media' => $lib, 'templates' => $tpl, 'lists' => $lists, 'flows' => $fl]);
        }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
