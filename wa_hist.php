<?php
/**
 * wa_hist.php — small helpers of the SHARED BRAIN. They need nothing from the WhatsApp files, so api.php can load this file from any "Teach by talking" action.
 *   - History of what the owner taught (Learning & Improvement → "What changed"), with a one-tap Undo for the changes that can be reversed.
 *   - A stub guidebook, so Practice chat and WhatsApp suggestions work from the owner's rules / limits / lessons even before a full guidebook is approved.
 *   - Limit enforcement in code: a reply that goes below the owner's lowest price, over his maximum discount, or offers a size he said never to sell is rewritten once, then replaced by a safe hand-over.
 *   - The log of what the bot did not know (Learning & Improvement → "What the bot noticed by itself").
 */
if (!function_exists('learn_hist_tables')) {

function learn_hist_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_learn_hist (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NULL, text VARCHAR(300) NOT NULL DEFAULT '', snap MEDIUMTEXT NULL, undone TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_gap (id INT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(8) NOT NULL, gkey VARCHAR(40) NOT NULL DEFAULT '', title VARCHAR(100) NOT NULL DEFAULT '', why VARCHAR(400) NOT NULL DEFAULT '', body VARCHAR(600) NOT NULL DEFAULT '',
            suggest VARCHAR(240) NOT NULL DEFAULT '', n INT NOT NULL DEFAULT 1, status VARCHAR(8) NOT NULL DEFAULT 'new', at DATETIME NULL, UNIQUE KEY uq_gap (kind, gkey)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
/** The current text of some lib_facts keys, to be able to put them back (Undo). */
function learn_snap(PDO $pdo, array $keys): array
{
    $o = []; foreach ($keys as $k) { try { $q = $pdo->prepare("SELECT body FROM lib_facts WHERE k = ?"); $q->execute([$k]); $o[$k] = (string)($q->fetchColumn() ?: ''); } catch (Throwable $e) { } } return $o;
}
/** Write one line into the history. $snap = the facts as they were BEFORE the change (from learn_snap) — then the line can be undone. Never throws. */
function learn_hist_add(PDO $pdo, string $text, array $snap = []): void
{
    try { if (!learn_hist_tables($pdo)) return;
        $pdo->prepare("INSERT INTO wa_learn_hist (at, text, snap, undone) VALUES (?, ?, ?, 0)")->execute([date('Y-m-d H:i:s'), mb_substr($text, 0, 300), $snap ? json_encode($snap, JSON_UNESCAPED_UNICODE) : null]);
        $pdo->exec("DELETE FROM wa_learn_hist WHERE id < " . ((int)$pdo->lastInsertId() - 200));
    } catch (Throwable $e) { }
}
function learn_hist_list(PDO $pdo): array
{
    if (!learn_hist_tables($pdo)) return [];
    $o = []; foreach ($pdo->query("SELECT id, at, text, (snap IS NOT NULL AND snap != '') AS u, undone FROM wa_learn_hist ORDER BY id DESC LIMIT 30")->fetchAll() as $r)
        $o[] = ['id' => (int)$r['id'], 'at' => (string)$r['at'], 'text' => (string)$r['text'] . ((int)$r['undone'] === 1 ? ' (undone)' : ''), 'can_undo' => (int)$r['u'] === 1 && (int)$r['undone'] === 0];
    return $o;
}
function learn_hist_undo(PDO $pdo, int $id): array
{
    if (!learn_hist_tables($pdo)) return [false, 'Storage is not available.'];
    $q = $pdo->prepare("SELECT * FROM wa_learn_hist WHERE id = ?"); $q->execute([$id]); $r = $q->fetch(); if (!$r) return [false, 'Not found.'];
    if ((int)$r['undone'] === 1) return [false, 'Already undone.']; $snap = json_decode((string)$r['snap'], true); if (!is_array($snap) || !$snap) return [false, 'This change cannot be undone.'];
    foreach ($snap as $k => $body) teach_facts_set($pdo, (string)$k, (string)$body);
    $pdo->prepare("UPDATE wa_learn_hist SET undone = 1 WHERE id = ?")->execute([$id]);
    $pdo->prepare("INSERT INTO wa_learn_hist (at, text, snap, undone) VALUES (?, ?, NULL, 0)")->execute([date('Y-m-d H:i:s'), mb_substr('Undid: ' . (string)$r['text'], 0, 300)]);
    return [true, ''];
}

/** What the bot did not know / blocked itself on. Same text counted again and again. */
function learn_gap_log(PDO $pdo, string $kind, string $text, string $why = ''): void
{
    try { if (!learn_hist_tables($pdo)) return; $text = trim(mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 300)); if ($text === '' && $why === '') return;
        $norm = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($kind === 'block' ? $why : $text))); $key = substr(md5($norm), 0, 20);
        $q = $pdo->prepare("SELECT id, status FROM wa_gap WHERE kind = ? AND gkey = ?"); $q->execute([$kind, $key]); $r = $q->fetch();
        if ($r) { $pdo->prepare("UPDATE wa_gap SET n = n + 1, at = ?, status = IF(status = 'ignored', 'ignored', 'new') WHERE id = ?")->execute([date('Y-m-d H:i:s'), (int)$r['id']]); return; }
        $pdo->prepare("INSERT INTO wa_gap (kind, gkey, title, why, body, suggest, n, status, at) VALUES (?, ?, '', ?, ?, '', 1, 'new', ?)")->execute([$kind, $key, mb_substr($why, 0, 400), $text, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) { }
}

// ---------------------------------------------------------------------------------------------------------------------- stub guidebook
/** A minimal guidebook so the bot can answer from the owner's rules, limits, lessons and facts alone. */
function lib_stub_guide(): array
{
    return ['stub' => true, 'about' => 'TM Perfume House, an Indian online perfume brand in Surat. No full guidebook is approved yet.',
        'note' => 'There is no approved guidebook yet. Answer ONLY from SELLING RULES, LIMITS, TEACHER LESSONS and STORE FACTS. If they do not cover what the customer needs, say you will confirm and hand over.'];
}

/** Practice chat (teach.bot) before any guidebook is approved: the same brain answers from the owner's rules / limits / lessons / facts. Answers and exits. */
function learn_bot_nolive(PDO $pdo): void
{
    $auth = require_auth(); lib_guard($pdo, $auth);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input(); $msgs = [];
    foreach (array_slice((array)($in['context'] ?? []), -6) as $m) { if (!is_array($m)) continue; $t = lib_clip((string)($m['text'] ?? ''), 400); if ($t === '') continue; $msgs[] = ['who' => ($m['who'] ?? '') === 'customer' ? 'customer' : 'bot', 'text' => $t]; }
    $cu = lib_clip((string)($in['customer'] ?? ''), 600); if ($cu === '') json_response(['error' => 'No customer message.'], 422); $msgs[] = ['who' => 'customer', 'text' => $cu];
    ignore_user_abort(true); @set_time_limit(150);
    [$ok, $v, $why] = lib_bot_reply($pdo, lib_stub_guide(), $msgs, 0, false, lib_clip((string)($in['situation'] ?? ''), 300));
    if (!$ok) json_response(['error' => $why], 502);
    json_response(learn_enforce_v($pdo, $v));
}

// ---------------------------------------------------------------------------------------------------------------------- limits enforced in code
function learn_label_re(string $label): ?string
{
    $l = mb_strtolower(trim($label)); if ($l === '') return null; $l = preg_replace('/\s+/u', '', $l); $esc = preg_quote($l, '/');
    if (preg_match('/^(\d+)(ml|gm|g)$/', $l, $m)) $esc = $m[1] . '\s*' . ($m[2] === 'ml' ? 'ml' : '(?:gm|g|gram|grams)');
    return '/(?<![\p{L}\p{N}])' . $esc . '(?![\p{L}])/iu';
}
/** Rupee amounts written in a reply. */
function learn_amounts(string $t): array
{
    $o = []; if (preg_match_all('/(?:₹|rs\.?|inr|rupees?)\s*([\d,]+(?:\.\d+)?)|([\d,]+(?:\.\d+)?)\s*(?:₹|rs\b|rupees?|rupaye|\/-)/iu', $t, $m, PREG_SET_ORDER))
        foreach ($m as $x) { $n = (float)str_replace(',', '', $x[1] !== '' ? $x[1] : ($x[2] ?? '')); if ($n > 0) $o[] = $n; }
    return $o;
}
/** Breaks of the owner's hard limits inside a reply (empty = fine). */
function learn_limit_violations(PDO $pdo, string $reply): array
{
    if (!function_exists('teach_limits_active')) return []; $A = teach_limits_active($pdo); if (!$A['rows'] && !$A['gen']) return [];
    $out = []; $amts = learn_amounts($reply); $low = mb_strtolower($reply);
    foreach ($A['rows'] as $r) {
        $re = learn_label_re((string)$r['label']); $hit = $re && preg_match($re, $reply);
        if (!empty($r['never']) && $hit) { $out[] = 'offers ' . $r['label'] . ' which you said to never sell'; continue; }
        if (!$hit) continue;
        if (($r['lowest'] ?? null) !== null) foreach ($amts as $a) if ($a >= 20 && $a < (float)$r['lowest']) { $out[] = 'quotes Rs ' . rtrim(rtrim(number_format($a, 2, '.', ''), '0'), '.') . ' for ' . $r['label'] . ', below your lowest price Rs ' . rtrim(rtrim(number_format((float)$r['lowest'], 2, '.', ''), '0'), '.'); }
    }
    $maxPct = null; foreach ($A['rows'] as $r) if (($r['max_pct'] ?? null) !== null) $maxPct = $maxPct === null ? (float)$r['max_pct'] : max($maxPct, (float)$r['max_pct']);
    if ($maxPct !== null && preg_match_all('/(\d+(?:\.\d+)?)\s*%/u', $reply, $m)) foreach ($m[1] as $p) if ((float)$p > $maxPct) $out[] = 'promises ' . $p . '% off, above your maximum ' . rtrim(rtrim(number_format($maxPct, 2, '.', ''), '0'), '.') . '%';
    return array_values(array_unique($out));
}
/** Take a bot answer (lib_bot_reply's array) and keep it inside the owner's limits: one rewrite, then a safe hand-over. Logs what it blocked. */
function learn_enforce_v(PDO $pdo, array $v): array
{
    $r = (string)($v['reply'] ?? ''); $bad = learn_limit_violations($pdo, $r); if (!$bad) return $v;
    foreach ($bad as $b) learn_gap_log($pdo, 'block', '', 'The bot ' . $b . ' — blocked');
    $fix = '';
    if (function_exists('convo_ai')) {
        $lt = function_exists('teach_limits_text') ? teach_limits_text($pdo) : '';
        $sch = ['type' => 'OBJECT', 'properties' => ['reply' => ['type' => 'STRING']], 'required' => ['reply']];
        try { [$ok, $x] = convo_ai("Rewrite this WhatsApp reply of a perfume shop so it stays inside the owner's HARD LIMITS. Problems: " . implode('; ', $bad) . ". Never quote a price below the lowest price, never promise a bigger discount than allowed, never offer a size marked never-sell. Keep language, script and warm short style.",
            [['text' => $lt . "\nREPLY TO FIX:\n" . $r]], $sch, false, 600, 'claude'); if ($ok && is_array($x)) $fix = trim((string)($x['reply'] ?? '')); } catch (Throwable $e) { }
    }
    if ($fix !== '' && !learn_limit_violations($pdo, $fix)) { $v['reply'] = $fix; $v['why'] = 'Rewritten: the first draft broke your limits (' . implode('; ', $bad) . ').'; $v['blocked'] = array_merge((array)($v['blocked'] ?? []), $bad); return $v; }
    $v['reply'] = 'Ek minute 🙏 main exact price confirm karke abhi batata hu.'; $v['handoff'] = true; $v['handoff_reason'] = 'would break a limit'; $v['why'] = 'Blocked: the draft broke your limits (' . implode('; ', $bad) . ').'; $v['blocked'] = array_merge((array)($v['blocked'] ?? []), $bad);
    return $v;
}

}
