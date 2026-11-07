<?php
/**
 * wa_cust.php — the Customers screen of the WhatsApp console (Contacts tab): filters → live count → pages → select → act.
 *
 *   Filters ("cf", one JSON object; every key optional — see wcs_clean):
 *     dk + df/dt   date range FIRST: dk = in (last wrote) · added (first message / import) · order (last order) · camp (got a campaign)
 *     tags_any[] / tags_all[] / tags_not[]   tags to include (any of / all of) and to leave out
 *     st · ord · ord_min/ord_max · sp_min/sp_max (₹ spent) · dsl · num (WhatsApp number) · emp (salesperson, -1 = nobody) · src · ign
 *   Selection ("sel"): {phones:[…]} the ticked people · or {all:1, cf, q, except:[…]} every match (minus the ones un-ticked)
 *
 *   wa.cs_meta        GET                         tags (grouped), numbers, salespeople, segments
 *   wa.cs_count       GET  {cf, q}                how many match (the live count)
 *   wa.cs_tag         POST {sel, tag, remove?}    add / remove one tag on everybody selected
 *   wa.cs_seg_save    POST {name, kind, sel|cf,q} 📁 segment: live (the filters — new matches join every hour) or fixed (exactly these people)
 *   wa.cs_seg_list    GET                         every segment with its size
 *   wa.cs_seg_del     POST {id}                   delete (its tag goes too)
 *   wa.cs_seg_refresh POST {id}                   rebuild a live segment now
 *   wa.cs_pick        POST {sel}                  the selection as a short-lived "picked" segment, for 📣 Manual / ✨ Smart campaign
 *
 * A segment IS a tag (seg_<slug>) on its members, so Campaigns, Smart campaigns, Tags & audience and every tag filter use it with no extra code.
 * Loaded by wa_more.php (before wa_seg.php). Admin / HR only. PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('wcs_tables')) {

function wcs_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_csegs (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL, tag VARCHAR(40) NOT NULL, kind VARCHAR(8) NOT NULL DEFAULT 'fixed',
            f MEDIUMTEXT NULL, q VARCHAR(80) NOT NULL DEFAULT '', n INT NOT NULL DEFAULT 0, by_emp INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, refreshed_at DATETIME NULL, UNIQUE KEY k_tag (tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_ctags (phone VARCHAR(12) NOT NULL, tag VARCHAR(40) NOT NULL, src VARCHAR(8) NOT NULL DEFAULT 'person', at DATETIME NULL, PRIMARY KEY (phone, tag), KEY k_tag (tag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (Throwable $e) { $ok = false; }
    return $ok;
}
function wcs_slug(string $s): string { $s = strtolower(trim($s)); $s = preg_replace('/[^a-z0-9]+/', '_', $s); return trim(mb_substr((string)$s, 0, 34), '_'); }
function wcs_date($v): string { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''; }
function wcs_tags($v): array
{
    if (!is_array($v)) $v = $v === '' || $v === null ? [] : explode(',', (string)$v);
    return array_slice(array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim((string)$x), 0, 40); }, $v), function ($x) { return $x !== ''; }))), 0, 30);
}
/** The Customers-screen filters → the audience filter keys of wa_aud_where (wa_scale.php). Unknown keys are dropped. */
function wcs_clean($raw): array
{
    $f = []; if (!is_array($raw)) return $f;
    $dk = (string)($raw['dk'] ?? 'in'); $df = wcs_date($raw['df'] ?? ''); $dt = wcs_date($raw['dt'] ?? '');
    $map = ['in' => ['in_from', 'in_to'], 'added' => ['added_from', 'added_to'], 'order' => ['lo_from', 'lo_to'], 'camp' => ['camp_from', 'camp_to']];
    if (isset($map[$dk])) { if ($df !== '') $f[$map[$dk][0]] = $df; if ($dt !== '') $f[$map[$dk][1]] = $dt; }
    if ($t = wcs_tags($raw['tags_any'] ?? [])) $f['gtags'] = $t;
    if ($t = wcs_tags($raw['tags_all'] ?? [])) $f['tags_all'] = $t;
    if ($t = wcs_tags($raw['tags_not'] ?? [])) $f['tags_not'] = $t;
    foreach (['st' => ['waiting', 'noreply', 'optout', 'reach'], 'ord' => ['0', '1', '2', '3'], 'dsl' => ['30', '60', '90', '91'], 'src' => ['query', 'campaign', 'community', 'none'], 'wrote' => ['7', '30', 'old']] as $k => $ok)
        if (isset($raw[$k]) && in_array((string)$raw[$k], $ok, true)) $f[$k] = (string)$raw[$k];
    foreach (['ord_min', 'ord_max', 'sp_min', 'sp_max', 'num', 'emp', 'ign'] as $k) if (isset($raw[$k]) && $raw[$k] !== '' && is_numeric($raw[$k])) $f[$k] = (int)$raw[$k];
    if (isset($f['ign']) && $f['ign'] <= 0) unset($f['ign']);
    return $f;
}
function wcs_sorts(): array { return ['latest', 'spent', 'orders', 'order_old', 'order_new', 'added', 'name']; }

/** Every phone of a selection (the ticked ones, or every match minus the un-ticked). Capped at 50,000. */
function wcs_sel_phones(PDO $pdo, $sel): array
{
    $sel = is_array($sel) ? $sel : []; $out = [];
    if (!empty($sel['all'])) {
        $ex = []; foreach ((array)($sel['except'] ?? []) as $p) $ex[(string)$p] = true;
        $f = wcs_clean(is_array($sel['cf'] ?? null) ? $sel['cf'] : []); $q = mb_substr(trim((string)($sel['q'] ?? '')), 0, 80);
        foreach (wa_aud_each($pdo, 'contacts', $f, $q) as $r) { if (!isset($ex[$r['phone']])) $out[] = (string)$r['phone']; if (count($out) >= 50000) break; }
        return $out;
    }
    foreach ((array)($sel['phones'] ?? []) as $p) { $k = function_exists('wa_key') ? wa_key((string)$p) : preg_replace('/\D/', '', (string)$p); if ($k !== '') $out[$k] = true; if (count($out) >= 50000) break; }
    return array_keys($out);
}
/** Add (or remove) one tag on many customers: the stored tag and the audience index, 500 at a time. */
function wcs_tag_many(PDO $pdo, array $phones, string $tag, bool $remove, string $src = 'bulk'): int
{
    if ($tag === '' || !$phones) return 0; $n = 0; $now = date('Y-m-d H:i:s');
    foreach (array_chunk($phones, 500) as $ch) {
        $in = implode(',', array_fill(0, count($ch), '?'));
        if ($remove) {
            $a = $pdo->prepare("DELETE FROM wa_ctags WHERE tag = ? AND phone IN ($in)"); $a->execute(array_merge([$tag], $ch)); $n += $a->rowCount();
            try { $pdo->prepare("DELETE FROM wa_tagidx WHERE tag = ? AND phone IN ($in)")->execute(array_merge([$tag], $ch)); } catch (Throwable $e) { }
        } else {
            $v = []; $p = []; foreach ($ch as $ph) { $v[] = '(?, ?, ?, ?)'; array_push($p, $ph, $tag, $src, $now); }
            $a = $pdo->prepare("INSERT IGNORE INTO wa_ctags (phone, tag, src, at) VALUES " . implode(',', $v)); $a->execute($p); $n += $a->rowCount();
            $v = []; $p = []; foreach ($ch as $ph) { $v[] = '(?, ?)'; array_push($p, $ph, $tag); }
            try { $pdo->prepare("INSERT IGNORE INTO wa_tagidx (phone, tag) VALUES " . implode(',', $v))->execute($p); } catch (Throwable $e) { }
        }
    }
    try { setting_set($pdo, 'wa_tag_cache', ''); } catch (Throwable $e) { }   // the tag counts are worked out again on the next look
    return $n;
}
/** A segment's members again (live: from its filters · fixed / picked: kept as they are). */
function wcs_seg_fill(PDO $pdo, array $s, array $phones): int
{
    $tag = (string)$s['tag'];
    $pdo->prepare("DELETE FROM wa_ctags WHERE tag = ?")->execute([$tag]); try { $pdo->prepare("DELETE FROM wa_tagidx WHERE tag = ?")->execute([$tag]); } catch (Throwable $e) { }
    wcs_tag_many($pdo, $phones, $tag, false, 'seg'); $n = count($phones);
    $pdo->prepare("UPDATE wa_csegs SET n = ?, refreshed_at = ? WHERE id = ?")->execute([$n, date('Y-m-d H:i:s'), (int)$s['id']]); return $n;
}
function wcs_seg_live_phones(PDO $pdo, array $s): array
{
    $f = json_decode((string)$s['f'], true); $f = is_array($f) ? $f : []; unset($f['gtags_self']); $out = [];
    foreach (wa_aud_each($pdo, 'contacts', $f, (string)$s['q']) as $r) { $out[] = (string)$r['phone']; if (count($out) >= 50000) break; } return $out;
}
function wcs_seg_create(PDO $pdo, string $name, string $kind, array $f, string $q, array $phones, int $by): array
{
    $name = trim(mb_substr($name, 0, 80)); if ($name === '') throw new RuntimeException('Give the segment a name.');
    $base = 'seg_' . (wcs_slug($name) ?: 'list'); $tag = $base; $i = 2;
    $chk = $pdo->prepare("SELECT COUNT(*) FROM wa_csegs WHERE tag = ?");
    while (true) { $chk->execute([$tag]); if (!(int)$chk->fetchColumn()) break; $tag = mb_substr($base, 0, 36) . '_' . $i++; }
    $pdo->prepare("INSERT INTO wa_csegs (name, tag, kind, f, q, n, by_emp, created_at) VALUES (?, ?, ?, ?, ?, 0, ?, ?)")->execute([$name, $tag, $kind, json_encode($f), mb_substr($q, 0, 80), $by, date('Y-m-d H:i:s')]);
    $s = ['id' => (int)$pdo->lastInsertId(), 'tag' => $tag, 'name' => $name, 'kind' => $kind, 'f' => json_encode($f), 'q' => $q];
    $n = wcs_seg_fill($pdo, $s, $kind === 'live' ? wcs_seg_live_phones($pdo, $s) : $phones);
    return ['id' => $s['id'], 'tag' => $tag, 'name' => $name, 'kind' => $kind, 'n' => $n];
}
function wcs_seg_names(PDO $pdo): array
{
    static $m = null; if ($m !== null) return $m; $m = [];
    try { if (wcs_tables($pdo)) foreach ($pdo->query("SELECT tag, name, kind FROM wa_csegs")->fetchAll() as $r) $m[(string)$r['tag']] = ($r['kind'] === 'pick' ? '🎯 ' : '📁 ') . $r['name']; } catch (Throwable $e) { }
    return $m;
}
/** Every hour (cron): live segments take in new matches; picked selections older than 60 days are removed. */
function wcs_tick(PDO $pdo): array
{
    if (!wcs_tables($pdo)) return []; $o = ['live' => 0, 'gone' => 0];
    $last = (string)setting_get($pdo, 'wcs_tick_at', ''); if ($last !== '' && strtotime($last) > time() - 3600) return $o; setting_set($pdo, 'wcs_tick_at', date('Y-m-d H:i:s'));
    foreach ($pdo->query("SELECT * FROM wa_csegs WHERE kind = 'live' ORDER BY refreshed_at LIMIT 30")->fetchAll() as $s) { try { wcs_seg_fill($pdo, $s, wcs_seg_live_phones($pdo, $s)); $o['live']++; } catch (Throwable $e) { } }
    $old = $pdo->prepare("SELECT * FROM wa_csegs WHERE kind = 'pick' AND created_at < ?"); $old->execute([date('Y-m-d H:i:s', time() - 60 * 86400)]);
    foreach ($old->fetchAll() as $s) { wcs_seg_delete($pdo, $s); $o['gone']++; }
    return $o;
}
function wcs_seg_delete(PDO $pdo, array $s): void
{
    $pdo->prepare("DELETE FROM wa_ctags WHERE tag = ?")->execute([(string)$s['tag']]); try { $pdo->prepare("DELETE FROM wa_tagidx WHERE tag = ?")->execute([(string)$s['tag']]); } catch (Throwable $e) { }
    $pdo->prepare("DELETE FROM wa_csegs WHERE id = ?")->execute([(int)$s['id']]); try { setting_set($pdo, 'wa_tag_cache', ''); } catch (Throwable $e) { }
}
function wcs_seg_public(array $s): array
{
    $f = json_decode((string)$s['f'], true);
    return ['id' => (int)$s['id'], 'name' => (string)$s['name'], 'tag' => (string)$s['tag'], 'kind' => (string)$s['kind'], 'n' => (int)$s['n'], 'cf' => is_array($f) ? ($f['_cf'] ?? null) : null, 'q' => (string)$s['q'],
        'created_at' => (string)$s['created_at'], 'refreshed_at' => (string)($s['refreshed_at'] ?? '')];
}

}   // function_exists guard

// ---------------------------------------------------------------------------------------------------------------- router
$__wcs = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__wcs, 'wa.cs_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('wa_tables') || !wa_tables($pdo) || !wcs_tables($pdo)) json_response(['error' => 'Storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    $me = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : [];
    $needPost = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $cfOf = function ($src) { $j = is_array($src) ? $src : json_decode((string)$src, true); return is_array($j) ? $j : []; };
    switch ($__wcs) {
        case 'wa.cs_meta': {
            $tags = function_exists('wag_tag_counts') ? (wag_tag_counts($pdo)['tags'] ?? []) : [];
            $nums = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll() as $n) $nums[] = ['id' => (int)$n['id'], 'label' => (string)($n['label'] !== '' ? $n['label'] : $n['display'])];
            $names = employee_names_by_id($pdo); $ppl = [];
            try { foreach ($pdo->query("SELECT assigned_emp AS e, COUNT(*) AS n FROM wa_contacts WHERE assigned_emp > 0 GROUP BY assigned_emp")->fetchAll() as $r) if (isset($names[(int)$r['e']])) $ppl[] = ['emp_id' => (int)$r['e'], 'name' => $names[(int)$r['e']], 'n' => (int)$r['n']]; } catch (Throwable $e) { }
            usort($ppl, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            $segs = array_map('wcs_seg_public', $pdo->query("SELECT * FROM wa_csegs ORDER BY kind = 'pick', created_at DESC LIMIT 200")->fetchAll());
            json_response(['ok' => true, 'tags' => $tags, 'numbers' => $nums, 'people' => $ppl, 'segments' => $segs]);
        }
        case 'wa.cs_count': {
            $f = wcs_clean($cfOf($_GET['cf'] ?? '')); $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
            wa_cust_ready($pdo); [$fq, $p] = wa_aud_where('contacts', $f, $q); $c = $pdo->prepare("SELECT COUNT(*), SUM(COALESCE(c.optout, 0) = 0) FROM $fq"); $c->execute($p); $r = $c->fetch(PDO::FETCH_NUM);
            json_response(['ok' => true, 'total' => (int)$r[0], 'reach' => (int)$r[1]]);
        }
        case 'wa.cs_tag': {
            $needPost(); $tag = wcs_slug((string)($in['tag'] ?? '')); if ($tag === '') json_response(['error' => 'Type a tag (letters and numbers).'], 422);
            if (strpos($tag, 'seg_') === 0) json_response(['error' => 'Tags starting with seg_ belong to segments — use 📁 Segment instead.'], 422);
            @set_time_limit(120); $ph = wcs_sel_phones($pdo, $in['sel'] ?? []); if (!$ph) json_response(['error' => 'Nobody is selected.'], 422);
            $n = wcs_tag_many($pdo, $ph, $tag, !empty($in['remove'])); json_response(['ok' => true, 'tag' => $tag, 'changed' => $n, 'selected' => count($ph)]);
        }
        case 'wa.cs_seg_save': {
            $needPost(); @set_time_limit(150); $kind = in_array($in['kind'] ?? '', ['live', 'fixed'], true) ? (string)$in['kind'] : 'fixed';
            $cf = $cfOf($in['cf'] ?? []); $q = mb_substr(trim((string)($in['q'] ?? '')), 0, 80); $f = wcs_clean($cf); $f['_cf'] = $cf;
            $ph = $kind === 'fixed' ? wcs_sel_phones($pdo, $in['sel'] ?? []) : []; if ($kind === 'fixed' && !$ph) json_response(['error' => 'Nobody is selected.'], 422);
            try { $s = wcs_seg_create($pdo, (string)($in['name'] ?? ''), $kind, $kind === 'live' ? $f : [], $kind === 'live' ? $q : '', $ph, $me); } catch (RuntimeException $e) { json_response(['error' => $e->getMessage()], 422); }
            json_response(['ok' => true, 'segment' => $s]);
        }
        case 'wa.cs_seg_list': json_response(['ok' => true, 'segments' => array_map('wcs_seg_public', $pdo->query("SELECT * FROM wa_csegs ORDER BY kind = 'pick', created_at DESC LIMIT 200")->fetchAll())]);
        case 'wa.cs_seg_del': {
            $needPost(); $s = $pdo->prepare("SELECT * FROM wa_csegs WHERE id = ?"); $s->execute([(int)($in['id'] ?? 0)]); $s = $s->fetch(); if (!$s) json_response(['error' => 'Segment not found.'], 404);
            wcs_seg_delete($pdo, $s); json_response(['ok' => true]);
        }
        case 'wa.cs_seg_refresh': {
            $needPost(); @set_time_limit(150); $s = $pdo->prepare("SELECT * FROM wa_csegs WHERE id = ?"); $s->execute([(int)($in['id'] ?? 0)]); $s = $s->fetch(); if (!$s) json_response(['error' => 'Segment not found.'], 404);
            if ($s['kind'] !== 'live') json_response(['ok' => true, 'n' => (int)$s['n']]);
            json_response(['ok' => true, 'n' => wcs_seg_fill($pdo, $s, wcs_seg_live_phones($pdo, $s))]);
        }
        case 'wa.cs_pick': {
            $needPost(); @set_time_limit(150); $ph = wcs_sel_phones($pdo, $in['sel'] ?? []); if (!$ph) json_response(['error' => 'Nobody is selected.'], 422);
            $name = 'Picked ' . date('j M H:i') . ' · ' . count($ph) . ' people';
            $s = wcs_seg_create($pdo, $name, 'pick', [], '', $ph, $me); json_response(['ok' => true, 'segment' => $s]);
        }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
