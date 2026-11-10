<?php
/**
 * wa_mix.php — marketing + utility mix for campaigns, one panel for all WhatsApp numbers, template families and the auto-submit engine.
 * Loaded (require_once) by wa_tpl.php and wa_camp.php. Everything here is idempotent; nothing here talks to Meta except through wa_tpl_create_at() / wa_api() / wa_tpl_model().
 *
 * THE IDEA IN PLAIN WORDS
 *  - Every pre-made message is sent to EVERY WhatsApp account (WABA) that has a number. Several numbers on one WABA share one approval. wa_tpl_waba is the matrix.
 *  - A marketing message M has a utility twin U (cheaper, Meta only keeps it as utility when it answers something the customer did). A running campaign mixes U in
 *    the moment U is approved on a number's WABA, but only for customers who asked about a perfume or ordered in the last 30 days (setting wa_mix_days).
 *  - How much U a number may carry comes from the number's health (wa_mix_share): it starts at 10 % and rises 10-15 points after each clean batch of 40+ utility sends,
 *    never above the health cap (Green 80 / Medium 40 / Low 0, scaled by the number's daily room), with a +10 probe after 3 clean batches in a row. A bad batch steps down 20.
 *  - wa_mix_plan() plans the target across all numbers: only numbers where U is really available carry U, the others send marketing, and ALL numbers still send.
 *  - If Meta re-labels U as marketing (or rejects / pauses / lowers the quality of a template) a stricter version is made and sent to all WABAs (families, max 3 per line).
 *
 * Tables: wa_mix_state (number_id, tpl_id, share, cap, clean_batches, last_batch_at, probe_until, note + clean_total, quality_seen, acc, updated_at), wa_mix_log, wa_tpl_subs.
 * Settings (all optional): wa_mix_days 30 · wa_mix_start 10 · wa_mix_batch 40 · wa_mix_ok_deliv 97 · wa_mix_ok_fail 2 · wa_mix_bad_fail 5 · wa_mix_settle_min 120 · wa_fam_max 3
 *   wa_tpl_tick_max 8 · wa_tpl_hour_cap 20 · wa_tpl_day_cap 80 · wa_tpl_total_cap 200 · wa_tpl_auto_twin 1 · wa_tpl_twin_backfill 0 · wa_tpl_auto 1
 */
if (!function_exists('wa_mix_tables')) {

/** Meta's rule: a utility message is only for something the customer did (order, delivery, account). Offers are marketing, however they are worded.
 *  Sending offers as utility twins (and rewriting them stricter each time Meta re-labels them) risks the whole WhatsApp account, so it is OFF.
 *  Utility stays for real order / delivery messages. Setting wa_util_mix = 1 brings the old mix back (not recommended). */
function wa_util_mix_on(PDO $pdo): bool { return function_exists('setting_get') && (string)setting_get($pdo, 'wa_util_mix', '0') === '1'; }

function wa_mix_cfg(PDO $pdo, string $k, $def)
{
    try { $v = setting_get($pdo, $k, null); } catch (Throwable $e) { $v = null; }
    return ($v === null || $v === '') ? $def : (is_int($def) ? (int)$v : $v);
}
function wa_mix_now(): int { if (defined('WA_CAMP_NOW')) return (int)WA_CAMP_NOW; return function_exists('wa_camp_now') ? wa_camp_now() : time(); }   // one clock for all (a test can fake it with WA_CAMP_NOW)
function wa_mix_tables(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok; if (function_exists('schema_done') && schema_done($pdo, __FUNCTION__, __FILE__)) return $ok = true;   // tables already set up for this upload
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_mix_state (number_id INT NOT NULL, tpl_id INT NOT NULL, share INT NOT NULL DEFAULT 10, cap INT NOT NULL DEFAULT 0, clean_batches INT NOT NULL DEFAULT 0, clean_total INT NOT NULL DEFAULT 0,
            last_batch_at DATETIME NULL, probe_until DATETIME NULL, quality_seen VARCHAR(10) NOT NULL DEFAULT '', acc INT NOT NULL DEFAULT 0, note VARCHAR(220) NOT NULL DEFAULT '', updated_at DATETIME NULL, PRIMARY KEY (number_id, tpl_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_mix_log (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NULL, number_id INT NOT NULL DEFAULT 0, tpl_id INT NOT NULL DEFAULT 0, old_share INT NOT NULL DEFAULT 0, new_share INT NOT NULL DEFAULT 0, why VARCHAR(220) NOT NULL DEFAULT '', KEY k_nt (number_id, tpl_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS wa_tpl_subs (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NULL, tpl_id INT NOT NULL DEFAULT 0, waba_id VARCHAR(30) NOT NULL DEFAULT '', ok TINYINT NOT NULL DEFAULT 0, note VARCHAR(220) NOT NULL DEFAULT '', KEY k_w (waba_id, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true; if (function_exists('schema_mark')) schema_mark($pdo, __FUNCTION__, __FILE__);
    } catch (PDOException $e) { $ok = false; }
    return $ok;
}
function wa_mix_extra(array $r): array { $x = $r['extra'] ?? []; if (is_string($x)) $x = json_decode($x, true); return is_array($x) ? $x : []; }
function wa_mix_extra_set(PDO $pdo, int $id, array $kv): void
{
    $q = $pdo->prepare("SELECT extra FROM wa_templates WHERE id = ?"); $q->execute([$id]); $x = wa_mix_extra(['extra' => $q->fetchColumn()]);
    foreach ($kv as $k => $v) { if ($v === null) unset($x[$k]); else $x[$k] = $v; }
    $pdo->prepare("UPDATE wa_templates SET extra = ? WHERE id = ?")->execute([$x ? json_encode($x, JSON_UNESCAPED_UNICODE) : null, $id]);
}
function wa_mix_q_rank(string $q): int { return ['GREEN' => 3, 'YELLOW' => 2, 'UNKNOWN' => 2, 'RED' => 1][strtoupper($q)] ?? 0; }
function wa_mix_q_name(string $q): string { return ['GREEN' => 'Green', 'YELLOW' => 'Medium', 'RED' => 'Low', 'UNKNOWN' => 'not rated yet'][strtoupper($q)] ?? 'not rated yet'; }

/** Numbers, their health (saved answer, no Meta call) and sending room. One call per tick / request. */
function wa_mix_env(PDO $pdo): array
{
    $health = function_exists('wa_camp_health') ? wa_camp_health($pdo) : []; $hb = []; foreach ($health as $h) $hb[(int)$h['id']] = $h;
    $caps = function_exists('wa_number_caps') ? wa_number_caps($pdo, $health) : []; $nums = [];
    foreach ($pdo->query("SELECT * FROM wa_numbers ORDER BY id")->fetchAll() as $n) { $id = (int)$n['id']; $n['waba'] = wa_waba_of($n); $n['label_txt'] = (string)$n['label'] !== '' ? (string)$n['label'] : ((string)$n['display'] !== '' ? (string)$n['display'] : 'Number ' . $id); $nums[$id] = $n; }
    return ['health' => $hb, 'caps' => $caps, 'numbers' => $nums];
}
function wa_mix_numlabel(array $env, int $nid): string { return $env['numbers'][$nid]['label_txt'] ?? ('Number ' . $nid); }

// ---------------------------------------------------------------------------------------------------------------- availability: one answer for "can this message go out from this number now?"
/** ['ok' => bool, 'why' => text, 'state' => ok|waiting|rejected|paused|relabelled|missing|error|health] */
function wa_tpl_avail(PDO $pdo, int $tplId, int $numberId, ?array $env = null): array
{
    $env = $env ?? wa_mix_env($pdo); $n = $env['numbers'][$numberId] ?? null; if (!$n) return ['ok' => false, 'why' => 'unknown number', 'state' => 'health'];
    $q = $pdo->prepare("SELECT category, relabelled_at FROM wa_templates WHERE id = ?"); $q->execute([$tplId]); $t = $q->fetch(); if (!$t) return ['ok' => false, 'why' => 'message not found', 'state' => 'missing'];
    $q = $pdo->prepare("SELECT status, reason FROM wa_tpl_waba WHERE tpl_id = ? AND waba_id = ?"); $q->execute([$tplId, $n['waba']]); $w = $q->fetch();
    if (!$w) return ['ok' => false, 'why' => 'not sent to this WhatsApp account yet', 'state' => 'missing'];
    $st = strtoupper((string)$w['status']);
    if ($st === 'PENDING' || $st === 'IN_APPEAL' || $st === 'PENDING_DELETION') return ['ok' => false, 'why' => 'waiting for Meta', 'state' => 'waiting'];
    if ($st === 'REJECTED') return ['ok' => false, 'why' => 'rejected by Meta' . ((string)$w['reason'] !== '' ? ' (' . mb_substr((string)$w['reason'], 0, 60) . ')' : ''), 'state' => 'rejected'];
    if ($st === 'PAUSED' || $st === 'DISABLED' || $st === 'FLAGGED') return ['ok' => false, 'why' => 'paused by Meta', 'state' => 'paused'];
    if ($st === 'ERROR') return ['ok' => false, 'why' => 'could not be sent to Meta yet - will try again', 'state' => 'error'];
    if ($st !== 'APPROVED') return ['ok' => false, 'why' => 'status ' . strtolower($st), 'state' => 'waiting'];
    if ($t['relabelled_at'] !== null && (string)$t['relabelled_at'] !== '') return ['ok' => false, 'why' => 'Meta re-labelled it as marketing', 'state' => 'relabelled'];
    $h = $env['health'][$numberId] ?? null;
    if ($h) {
        if ((string)$h['status'] !== '' && $h['status'] !== 'CONNECTED') return ['ok' => false, 'why' => 'the number is not connected', 'state' => 'health'];
        if (($h['quality'] ?? '') === 'RED') return ['ok' => false, 'why' => 'the number quality is Low', 'state' => 'health'];
    }
    if ((int)($n['camp_ok'] ?? 1) !== 1) return ['ok' => false, 'why' => 'the number is switched off for campaigns', 'state' => 'health'];
    return ['ok' => true, 'why' => '', 'state' => 'ok'];
}

// ---------------------------------------------------------------------------------------------------------------- the utility share of one number (health model)
function wa_mix_state_row(PDO $pdo, int $nid, int $tplId): array
{
    wa_mix_tables($pdo); $q = $pdo->prepare("SELECT * FROM wa_mix_state WHERE number_id = ? AND tpl_id = ?"); $q->execute([$nid, $tplId]); $r = $q->fetch();
    if ($r) return $r;
    $start = wa_mix_cfg($pdo, 'wa_mix_start', 10);
    $pdo->prepare("INSERT IGNORE INTO wa_mix_state (number_id, tpl_id, share, note, updated_at) VALUES (?, ?, ?, ?, ?)")->execute([$nid, $tplId, $start, 'Starting at ' . $start . '% - rises step by step while the sends stay clean.', date('Y-m-d H:i:s', wa_mix_now())]);
    $q->execute([$nid, $tplId]); return $q->fetch();
}
function wa_mix_logrow(PDO $pdo, int $nid, int $tplId, int $old, int $new, string $why): void
{
    try { $pdo->prepare("INSERT INTO wa_mix_log (at, number_id, tpl_id, old_share, new_share, why) VALUES (?, ?, ?, ?, ?, ?)")->execute([date('Y-m-d H:i:s', wa_mix_now()), $nid, $tplId, $old, $new, mb_substr($why, 0, 220)]);
          $pdo->exec("DELETE FROM wa_mix_log WHERE id < (SELECT m FROM (SELECT MAX(id) - 2000 AS m FROM wa_mix_log) t)"); } catch (PDOException $e) { }
}
/** The most utility this number may carry right now: [cap, why]. GREEN 80 (90 / 100 after 10 / 20 clean batches in all), Medium 40, Low or off 0; then scaled by the number's daily room. */
function wa_mix_cap(array $st, array $h, array $caps, int $nid): array
{
    $q = strtoupper((string)($h['quality'] ?? 'UNKNOWN')); $conn = !isset($h['status']) || (string)$h['status'] === '' || $h['status'] === 'CONNECTED';
    if (!$conn) return [0, 'number not connected']; if ($q === 'RED') return [0, 'quality is Low']; if (!isset($caps[$nid]) || (int)$caps[$nid]['cap'] <= 0) return [0, (string)($caps[$nid]['why'] ?? '') ?: 'no sending room'];
    $base = $q === 'GREEN' ? 80 : 40; if ($q === 'GREEN') { $ct = (int)$st['clean_total']; if ($ct >= 20) $base = 100; elseif ($ct >= 10) $base = 90; }
    $dc = (int)$caps[$nid]['cap']; $f = $dc >= 5000 ? 1.0 : ($dc >= 1000 ? 0.9 : ($dc >= 250 ? 0.75 : 0.5));
    return [min(100, (int)(round($base * $f / 5) * 5)), ''];
}
/** A bad batch / quality drop / relabel: step down, pause probing for 24 h. */
function wa_mix_step_down(PDO $pdo, array $st, string $why, int $drop = 20, ?int $to = null): array
{
    $old = (int)$st['share']; $new = $to !== null ? $to : max(5, $old - $drop); $now = wa_mix_now();
    $pdo->prepare("UPDATE wa_mix_state SET share = ?, clean_batches = 0, probe_until = ?, note = ?, updated_at = ? WHERE number_id = ? AND tpl_id = ?")->execute([$new, date('Y-m-d H:i:s', $now + 86400), mb_substr($why, 0, 220), date('Y-m-d H:i:s', $now), $st['number_id'], $st['tpl_id']]);
    wa_mix_logrow($pdo, (int)$st['number_id'], (int)$st['tpl_id'], $old, $new, $why); $st['share'] = $new; $st['clean_batches'] = 0; $st['note'] = $why; return $st;
}
/** Judges the utility sends of this number that are old enough to be settled. Moves the share one step. Returns the (possibly changed) state row. */
function wa_mix_eval(PDO $pdo, array $st, array $h, array $caps, string $quality): array
{
    $now = wa_mix_now(); $min = wa_mix_cfg($pdo, 'wa_mix_batch', 40); $settle = wa_mix_cfg($pdo, 'wa_mix_settle_min', 120);
    $q = $pdo->prepare("SELECT i.sent_at, i.status, i.last_status, i.err_code, i.tries, m.status AS ms FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id LEFT JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> ''
        WHERE c.tpl_id = ? AND i.variant = 'U' AND i.number_id = ? AND i.sent_at > ? AND i.sent_at <= ? AND (i.status IN ('sent', 'failed', 'retry') OR (i.status = 'skipped' AND i.err_code IN (131049, 131048))) ORDER BY i.sent_at LIMIT 3000");
    $q->execute([$st['tpl_id'], $st['number_id'], $st['last_batch_at'] ?: '1970-01-01 00:00:00', date('Y-m-d H:i:s', $now - $settle * 60)]); $rows = $q->fetchAll();
    if (count($rows) < $min) return $st;
    $n = count($rows); $dl = 0; $fl = 0; $sp = 0; $att = 0; $last = $st['last_batch_at'];   // a failed send is turned into a "retry" by the engine, so every ATTEMPT counts: tries - 1 earlier failures + the last one if it failed
    foreach ($rows as $r) {
        $s = (string)$r['ms']; $ls = (string)$r['last_status']; $last = (string)$r['sent_at']; $tr = max(1, (int)$r['tries']); $att += $tr;
        $lastFailed = $s === 'failed' || $ls === 'failed' || in_array($r['status'], ['failed', 'retry', 'skipped'], true); $fl += ($tr - 1) + ($lastFailed ? 1 : 0);
        if (!$lastFailed && (in_array($s, ['delivered', 'read'], true) || in_array($ls, ['delivered', 'read'], true))) $dl++;
        if (in_array((int)$r['err_code'], [131049, 131048], true)) $sp++;
    }
    $dp = $dl * 100 / $n; $fp = $fl * 100 / max(1, $att); $name = 'last ' . $n . ' sends';
    $pdo->prepare("UPDATE wa_mix_state SET last_batch_at = ? WHERE number_id = ? AND tpl_id = ?")->execute([$last, $st['number_id'], $st['tpl_id']]); $st['last_batch_at'] = $last;
    if ($fp > wa_mix_cfg($pdo, 'wa_mix_bad_fail', 5) || $sp >= max(2, (int)ceil($n * 0.03))) {
        $why = $name . ': ' . ($sp >= max(2, (int)ceil($n * 0.03)) ? $sp . ' blocked by Meta (131049 / 131048)' : round($fp) . '% failed') . ' - stepped down';
        return wa_mix_step_down($pdo, $st, $why);
    }
    if ($dp >= wa_mix_cfg($pdo, 'wa_mix_ok_deliv', 97) && $fp <= wa_mix_cfg($pdo, 'wa_mix_ok_fail', 2) && wa_mix_q_rank($quality) >= wa_mix_q_rank((string)$st['quality_seen'])) {
        [$cap] = wa_mix_cap($st, $h, $caps, (int)$st['number_id']); $cb = (int)$st['clean_batches'] + 1; $old = (int)$st['share'];
        $probe = $quality === 'GREEN' && $cb >= 3 && ($st['probe_until'] === null || strtotime((string)$st['probe_until']) <= $now);
        $ceil = $cap > 0 ? ($probe ? min(100, $cap + 10) : $cap) : 0; $step = $old < 40 ? 15 : 10; $new = min($ceil, $old + $step); if ($new < $old) $new = $old;
        $note = $name . ' clean (delivered ' . round($dp) . '%, failed ' . round($fp) . '%), quality ' . wa_mix_q_name($quality) . ($new > $old ? ' - up to ' . $new . '%' : ($cap > 0 ? ' - at the safe limit ' . $cap . '%' : ''));
        $pdo->prepare("UPDATE wa_mix_state SET share = ?, clean_batches = ?, clean_total = clean_total + 1, note = ?, updated_at = ? WHERE number_id = ? AND tpl_id = ?")->execute([$new, $cb, mb_substr($note, 0, 220), date('Y-m-d H:i:s', $now), $st['number_id'], $st['tpl_id']]);
        if ($new !== $old) wa_mix_logrow($pdo, (int)$st['number_id'], (int)$st['tpl_id'], $old, $new, $note);
        $st['share'] = $new; $st['clean_batches'] = $cb; $st['clean_total'] = (int)$st['clean_total'] + 1; $st['note'] = $note; return $st;
    }
    $note = $name . ': delivered ' . round($dp) . '%, failed ' . round($fp) . '% - not clean enough to go up, staying at ' . $st['share'] . '%';
    $pdo->prepare("UPDATE wa_mix_state SET clean_batches = 0, note = ?, updated_at = ? WHERE number_id = ? AND tpl_id = ?")->execute([mb_substr($note, 0, 220), date('Y-m-d H:i:s', $now), $st['number_id'], $st['tpl_id']]);
    $st['clean_batches'] = 0; $st['note'] = $note; return $st;
}
/**
 * The utility share for one number and one marketing message: ['share' => 0..100 (of the customers who may get it), 'cap' => the safe limit now, 'why' => one line for the screen, ...].
 * $advance = true also judges finished batches and reacts to a quality drop (the engine); false only reads (screens).  $env = wa_mix_env() when the caller has it already.
 */
function wa_mix_share(PDO $pdo, int $numberId, int $tplId, bool $advance = true, ?array $env = null): array
{
    $env = $env ?? wa_mix_env($pdo); $st = wa_mix_state_row($pdo, $numberId, $tplId); $h = $env['health'][$numberId] ?? ['quality' => 'UNKNOWN', 'status' => '']; $q = strtoupper((string)($h['quality'] ?? 'UNKNOWN')); $now = wa_mix_now();
    if ($advance) {
        if ((string)$st['quality_seen'] !== '' && wa_mix_q_rank($q) < wa_mix_q_rank((string)$st['quality_seen'])) $st = wa_mix_step_down($pdo, $st, 'Number quality fell from ' . wa_mix_q_name((string)$st['quality_seen']) . ' to ' . wa_mix_q_name($q) . ' - stepped down');
        $st = wa_mix_eval($pdo, $st, $h, $env['caps'], $q);
        if ((string)$st['quality_seen'] !== $q) { $pdo->prepare("UPDATE wa_mix_state SET quality_seen = ? WHERE number_id = ? AND tpl_id = ?")->execute([$q, $numberId, $tplId]); $st['quality_seen'] = $q; }
    }
    [$cap, $capWhy] = wa_mix_cap($st, $h, $env['caps'], $numberId);
    $probeOk = $cap > 0 && $q === 'GREEN' && (int)$st['share'] >= $cap && (int)$st['clean_batches'] >= 3 && ($st['probe_until'] === null || strtotime((string)$st['probe_until']) <= $now);
    $ceil = $cap > 0 ? ($probeOk ? min(100, $cap + 10) : $cap) : 0; $share = max(0, min(100, min((int)$st['share'], $ceil)));
    $lab = wa_mix_numlabel($env, $numberId);
    $why = $cap <= 0 ? $lab . ': no utility - ' . $capWhy : $lab . ': utility ' . $share . '%' . ((string)$st['note'] !== '' ? ' - ' . $st['note'] : '') . ($share > $cap ? ' (small probe above the safe limit)' : '');
    return ['share' => $share, 'cap' => $cap, 'why' => $why, 'stored' => (int)$st['share'], 'clean_batches' => (int)$st['clean_batches'], 'clean_total' => (int)$st['clean_total'], 'quality' => $q, 'probe' => $share > $cap, 'probe_until' => $st['probe_until'], 'note' => (string)$st['note'], 'acc' => (int)$st['acc']];
}
/** A utility twin was re-labelled by Meta: every number that was building trust with it starts again at the start share and may not probe for 24 h. */
function wa_mix_on_relabel(PDO $pdo, int $uTplId): void
{
    if (!wa_util_mix_on($pdo)) return;   // no stricter utility version: the message is simply used as marketing
    wa_mix_tables($pdo); $q = $pdo->prepare("SELECT extra FROM wa_templates WHERE id = ?"); $q->execute([$uTplId]); $mId = (int)(wa_mix_extra(['extra' => $q->fetchColumn()])['utility_of'] ?? 0); if ($mId <= 0) return;
    $start = wa_mix_cfg($pdo, 'wa_mix_start', 10); $s = $pdo->prepare("SELECT * FROM wa_mix_state WHERE tpl_id = ?"); $s->execute([$mId]);
    foreach ($s->fetchAll() as $st) wa_mix_step_down($pdo, $st, 'Meta re-labelled the utility message as marketing - back to ' . $start . '%, a stricter version is on its way', 20, $start);
}

// ---------------------------------------------------------------------------------------------------------------- twins and families
function wa_mix_twins(PDO $pdo, int $mId): array
{
    $o = []; foreach ($pdo->query("SELECT * FROM wa_templates WHERE extra LIKE '%\"utility_of\"%'")->fetchAll() as $r) {
        $x = wa_mix_extra($r); if ((int)($x['utility_of'] ?? 0) !== $mId) continue; $r['ex'] = $x; $r['ver'] = (int)($r['version'] ?? 0) ?: (int)($x['utility_v'] ?? 1); $o[] = $r;
    }
    usort($o, fn($a, $b) => $a['ver'] <=> $b['ver'] ?: (int)$a['id'] <=> (int)$b['id']); return $o;
}
/** Every message of a family (the original and all its versions and utility twins). */
function wa_fam_members(PDO $pdo, int $root): array
{
    $q = $pdo->prepare("SELECT * FROM wa_templates WHERE id = ? OR family_id = ? ORDER BY id"); $q->execute([$root, $root]); $o = [];
    foreach ($q->fetchAll() as $r) { $x = wa_mix_extra($r); $r['ex'] = $x; $r['line'] = (int)($x['utility_of'] ?? 0) > 0 ? 'U' : 'M'; $r['ver'] = (int)($r['version'] ?? 0) ?: (int)($x['utility_v'] ?? 1); $o[] = $r; }
    return $o;
}
function wa_fam_root(array $r): int { return (int)($r['family_id'] ?? 0) ?: (int)$r['id']; }
/** Other approved versions of the same marketing message (an alternative made when the first one had trouble). Same variables, same top. */
function wa_fam_m_alts(PDO $pdo, array $t): array
{
    $root = wa_fam_root($t); $o = [];
    foreach (wa_fam_members($pdo, $root) as $m) {
        if ($m['line'] !== 'M' || (int)$m['id'] === (int)$t['id'] || $m['relabelled_at']) continue;
        if (strtoupper((string)$m['header_format']) !== strtoupper((string)$t['header_format']) || count(wa_tpl_vars((string)$m['body'])) !== (int)$t['nvars']) continue;
        $c = wa_camp_tpl($pdo, (int)$m['id'], true, (int)($t['header_lib_id'] ?? 0)); if ($c) $o[] = $c;
    }
    usort($o, fn($a, $b) => (int)($a['version'] ?? 1) <=> (int)($b['version'] ?? 1)); return $o;
}

// ---------------------------------------------------------------------------------------------------------------- who may get the utility version
/** Cheap check from rows we already have: wrote to us, or ordered, in the last wa_mix_days days. */
function wa_mix_cheap_ok(PDO $pdo, array $ct, $om, int $nowTs): bool
{
    $days = wa_mix_cfg($pdo, 'wa_mix_days', 30); $from = $nowTs - $days * 86400; if ((int)($ct['optout'] ?? 0) === 1) return false;
    if (!empty($ct['last_in_at']) && strtotime((string)$ct['last_in_at']) >= $from) return true;
    if ($om && !empty($om['last']) && (int)$om['n'] > 0 && strtotime((string)$om['last']) >= $from) return true;
    return false;
}
function wa_mix_perfume(PDO $pdo, string $phone, bool $asked): string
{
    if (function_exists('wa_mix_perfume_override')) return (string)wa_mix_perfume_override($phone, $asked);   // a test can fix the answer
    try {
        if ($asked) { $v = wa_camp_lazy('wa_prod_last_asked', 'wa_prod.php') ? (string)wa_prod_last_asked($pdo, $phone) : ''; if (wa_camp_clean_var($v) !== '') return $v; }
        $v = wa_camp_lazy('wa_prod_last_ordered', 'wa_prod.php') ? (string)wa_prod_last_ordered($pdo, $phone) : ''; return wa_camp_clean_var($v) !== '' ? $v : '';
    } catch (Throwable $e) { return ''; }
}
/** The variables of the utility message for ONE customer, or null when something it says would not be true (then the customer simply gets the marketing version). */
function wa_mix_u_vars(PDO $pdo, array $u, array $ct, $om, int $nowTs): ?array
{
    $days = wa_mix_cfg($pdo, 'wa_mix_days', 30); $from = $nowTs - $days * 86400; $body = (string)$u['body']; $n = count(wa_tpl_vars($body));
    $ex = is_array($u['examples'] ?? null) ? $u['examples'] : []; $labels = array_map('strtolower', array_map('strval', (array)($ex['labels'] ?? [])));
    $inAt = !empty($ct['last_in_at']) ? (int)strtotime((string)$ct['last_in_at']) : 0; $askedRecently = $inAt >= $from; $ordAt = ($om && !empty($om['last']) && (int)$om['n'] > 0) ? (int)strtotime((string)$om['last']) : 0; $ordRecently = $ordAt >= $from && $ordAt > 0;
    $perfume = null; $date = null;
    $getP = function () use (&$perfume, $pdo, $ct, $askedRecently, $ordRecently) { if ($perfume === null) { $perfume = ''; if ($askedRecently) $perfume = wa_mix_perfume($pdo, (string)$ct['phone'], true); if ($perfume === '' && $ordRecently) $perfume = wa_mix_perfume($pdo, (string)$ct['phone'], false); } return $perfume; };
    $getD = function () use (&$date, $askedRecently, $ordRecently, $inAt, $ordAt, $getP) { if ($date === null) { $date = ''; $p = $getP(); $t = ($askedRecently && $p !== '') ? $inAt : ($ordRecently ? $ordAt : 0); if ($t > 0) $date = date('j M', $t); } return $date; };
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $lab = $labels[$i] ?? ''; $pos = strpos($body, '{{' . ($i + 1) . '}}'); $before = strtolower(substr($body, max(0, (int)$pos - 40), min(40, (int)$pos)));
        if (strpos($lab, 'name') !== false || ($lab === '' && $i === 0)) $v = wa_first_name((string)($ct['name'] ?? ''));
        elseif (strpos($lab, 'perfume') !== false || preg_match('/perfume|baare|about|asked|poocha/', $lab === '' ? $before : '')) $v = $getP();
        elseif (strpos($lab, 'date') !== false || preg_match('/\bon\b|\bko\b|date/', $lab === '' ? $before : '')) $v = $getD();
        else return null;
        $v = wa_camp_clean_var((string)$v, 300); if ($v === '') return null; $out[] = $v;
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------------------------- the plan across all numbers
/**
 * Plans the utility share across ALL numbers for one campaign. $numbers = [id => ['left' => room today, ...]] (the sending numbers; the screens pass every number).
 * Returns ['on','target','achieved','shortfall','u_status','per_number'=>[id=>[...]],'queue'].  Per number: u (the utility template to use or null), u_state, share (planned), health_share, cap, why, acc, elig, u_given.
 * target = what the health model allows if every number could carry U (room-weighted); achieved = what the numbers that really can carry U give.  $carry = the previous plan (keeps the running counters).
 */
function wa_mix_plan(PDO $pdo, array $camp, array $numbers, int $queueSize, ?array $carry = null, bool $advance = true, ?array $env = null): array
{
    $ex = is_array($camp['extra'] ?? null) ? $camp['extra'] : []; $mId = (int)$camp['tpl_id']; $on = !empty($ex['mix']);
    $plan = ['on' => $on, 'target' => 0.0, 'achieved' => 0.0, 'shortfall' => '', 'u_status' => 'none', 'per_number' => [], 'queue' => $queueSize, 'tpl_id' => $mId];
    if (!$on || !wa_mix_tables($pdo)) return $plan;
    $env = $env ?? wa_mix_env($pdo); $twins = wa_mix_twins($pdo, $mId); $rev = array_reverse($twins); $sumW = 0; $sumT = 0; $sumA = 0; $bad = []; $wait = []; $any = [];
    foreach ($numbers as $nid => $nm) {
        $nid = (int)$nid; $w = max(1, (int)($nm['left'] ?? 1)); $sh = wa_mix_share($pdo, $nid, $mId, $advance, $env); $u = null; $state = $twins ? 'waiting' : 'none'; $why = $twins ? '' : 'no utility version yet'; $uRow = null;
        foreach ($rev as $tw) { $av = wa_tpl_avail($pdo, (int)$tw['id'], $nid, $env); if ($av['ok']) { $u = $tw; break; } }
        if ($u) {
            $c = $carry['tcache'][(int)$u['id']] ?? wa_camp_tpl($pdo, (int)$u['id'], true, (int)($camp['media_lib_id'] ?? 0));
            if ($c) { $uRow = $c; $state = 'on'; } else { $u = null; $state = 'waiting'; $why = 'cannot be sent from here (file missing)'; }
        } elseif ($twins) {
            $L = end($twins); $av = wa_tpl_avail($pdo, (int)$L['id'], $nid, $env); $state = $av['state'] === 'ok' ? 'waiting' : $av['state']; $why = $av['why'];
            if ($L['relabelled_at']) { $state = (int)$L['ver'] >= wa_mix_cfg($pdo, 'wa_fam_max', 3) ? 'stopped' : 'relabelled'; $why = $state === 'stopped' ? 'Meta keeps re-labelling it - needs a human to rewrite' : 'Meta re-labelled it as marketing - a stricter version is being made'; }
        }
        $planned = $uRow ? $sh['share'] : 0;
        $pn = ['id' => $nid, 'label' => wa_mix_numlabel($env, $nid), 'left' => (int)($nm['left'] ?? 0), 'u' => $uRow, 'u_id' => $uRow ? (int)$uRow['id'] : 0, 'u_state' => $state, 'u_why' => $why, 'share' => (int)$planned, 'health_share' => (int)$sh['share'], 'cap' => (int)$sh['cap'], 'why' => $sh['why'],
               'quality' => $sh['quality'], 'acc' => (int)($carry['per_number'][$nid]['acc'] ?? $sh['acc']), 'elig' => (int)($carry['per_number'][$nid]['elig'] ?? 0), 'u_given' => (int)($carry['per_number'][$nid]['u_given'] ?? 0), 'acc_dirty' => (bool)($carry['per_number'][$nid]['acc_dirty'] ?? false)];
        $plan['per_number'][$nid] = $pn; $sumW += $w; $sumT += $w * $sh['share']; $sumA += $w * $planned;
        if ($twins) { $any[] = $nid; if ($state !== 'on') { if (in_array($state, ['waiting', 'missing', 'error'], true)) $wait[] = $pn['label']; else $bad[] = $pn['label'] . ' (' . ($state === 'relabelled' ? 're-labelled' : ($state === 'stopped' ? 'needs a human' : ($state === 'rejected' ? 'rejected' : ($state === 'paused' ? 'paused' : 'number not ready')))) . ')'; } } }
    if ($sumW > 0 && $twins) { $plan['target'] = round($sumT / $sumW, 1); $plan['achieved'] = round($sumA / $sumW, 1); }
    $plan['u_status'] = !$twins ? 'none' : (array_filter($plan['per_number'], fn($p) => $p['u']) ? 'approved' : (end($twins)['relabelled_at'] ? ((int)end($twins)['ver'] >= wa_mix_cfg($pdo, 'wa_fam_max', 3) ? 'stopped' : 'relabelled') : (wa_mix_all_state($plan, 'rejected') ? 'rejected' : 'waiting')));
    if ($twins && $plan['target'] - $plan['achieved'] >= 1) {
        $plan['shortfall'] = 'utility ' . round($plan['achieved']) . '% of ' . round($plan['target']) . '% target' . ($bad || $wait ? ': ' . implode(', ', array_merge(array_map(fn($x) => $x . ' blocked', $bad), array_map(fn($x) => $x . ' waiting', $wait))) : '');
        $plan['shortfall'] = preg_replace('/blocked \(/', '(', $plan['shortfall']);
    }
    $plan['tcache'] = $carry['tcache'] ?? []; foreach ($plan['per_number'] as $p) if ($p['u']) $plan['tcache'][(int)$p['u_id']] = $p['u'];
    return $plan;
}
function wa_mix_all_state(array $plan, string $s): bool { foreach ($plan['per_number'] as $p) if ($p['u_state'] === $s) return true; return false; }
/** Called for ONE customer who is about to get the message from number $nid. Returns null (send the marketing version) or ['t' => the utility template, 'vars' => its variables]. */
function wa_mix_take(PDO $pdo, array &$plan, int $nid, array $ct, $om, int $nowTs): ?array
{
    $pn = &$plan['per_number'][$nid]; if (!$pn || empty($pn['u']) || (int)$pn['share'] <= 0) return null;
    if (!wa_mix_cheap_ok($pdo, $ct, $om, $nowTs)) return null;
    $pn['acc'] += (int)$pn['share']; $pn['elig']++; $pn['acc_dirty'] = true;
    if ($pn['acc'] < 100) return null;
    $vars = wa_mix_u_vars($pdo, $pn['u'], $ct, $om, $nowTs); if ($vars === null) return null;   // not true for this person: the owed turn stays for the next one
    $pn['acc'] -= 100; $pn['u_given']++; return ['t' => $pn['u'], 'vars' => $vars];
}
function wa_mix_save(PDO $pdo, array $plan): void
{
    foreach ($plan['per_number'] ?? [] as $nid => $p) if (!empty($p['acc_dirty'])) { try { $pdo->prepare("UPDATE wa_mix_state SET acc = ? WHERE number_id = ? AND tpl_id = ?")->execute([max(0, min(1000, (int)$p['acc'])), (int)$nid, (int)$plan['tpl_id']]); } catch (PDOException $e) { } }
}

// ---------------------------------------------------------------------------------------------------------------- reports for the screens
function wa_mix_variants(PDO $pdo, int $campId): array
{
    $o = ['M' => ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'failed' => 0], 'U' => ['sent' => 0, 'delivered' => 0, 'read' => 0, 'replied' => 0, 'failed' => 0]];
    try {
        $q = $pdo->prepare("SELECT COALESCE(NULLIF(t.variant, ''), 'M') AS v, t.ds, COUNT(*) AS n FROM (" . wa_camp_ds_sql() . ") t GROUP BY v, t.ds"); $q->execute([$campId]);
        foreach ($q->fetchAll() as $r) { $v = $r['v'] === 'U' ? 'U' : 'M'; $n = (int)$r['n']; $ds = (string)$r['ds'];
            if (in_array($ds, ['sent', 'delivered', 'read', 'replied'], true)) $o[$v]['sent'] += $n; if (in_array($ds, ['delivered', 'read', 'replied'], true)) $o[$v]['delivered'] += $n; if (in_array($ds, ['read', 'replied'], true)) $o[$v]['read'] += $n; if ($ds === 'replied') $o[$v]['replied'] += $n; if ($ds === 'failed') $o[$v]['failed'] += $n; }
    } catch (PDOException $e) { }
    return $o;
}
/** The twin a campaign is linked to: the newest one that is approved somewhere and not re-labelled, else the newest that is not re-labelled, else the last one. */
function wa_mix_best_twin(PDO $pdo, array $twins): ?array
{
    if (!$twins) return null; $wabas = wa_wabas($pdo);
    foreach (array_reverse($twins) as $tw) if (!$tw['relabelled_at'] && wa_mix_tpl_state($pdo, (int)$tw['id'], $wabas) === 'approved') return $tw;
    foreach (array_reverse($twins) as $tw) if (!$tw['relabelled_at']) return $tw;
    return end($twins);
}
/** The "mix" object of a campaign for the screens (read only). */
function wa_mix_report(PDO $pdo, array $camp, array $cfg, bool $deep = true): array
{
    $ex = is_array($camp['extra'] ?? null) ? $camp['extra'] : []; $mId = (int)$camp['tpl_id']; $on = !empty($ex['mix']); $twins = wa_mix_twins($pdo, $mId);
    $o = ['on' => $on, 'tpl_u' => null, 'u_status' => 'none', 'target' => 0, 'achieved' => 0, 'shortfall' => '', 'per_number' => [], 'variants' => null, 'relabel' => [], 'stopped' => '', 'cost' => null, 'days' => wa_mix_cfg($pdo, 'wa_mix_days', 30)];
    if ($twins) { $L = wa_mix_best_twin($pdo, $twins); $o['tpl_u'] = ['id' => (int)$L['id'], 'name' => (string)$L['name'], 'version' => (int)$L['ver']]; }
    foreach ($twins as $tw) if ($tw['relabelled_at']) { $nx = null; foreach ($twins as $t2) if ($t2['ver'] > $tw['ver']) { $nx = $t2; break; } $o['relabel'][] = ['id' => (int)$tw['id'], 'name' => (string)$tw['name'], 'version' => (int)$tw['ver'], 'at' => (string)$tw['relabelled_at'], 'new_id' => $nx ? (int)$nx['id'] : 0, 'new_name' => $nx ? (string)$nx['name'] : '', 'new_status' => $nx ? wa_mix_tpl_state($pdo, (int)$nx['id'], wa_wabas($pdo)) : ''];
    }
    if ($twins && end($twins)['relabelled_at'] && (int)end($twins)['ver'] >= wa_mix_cfg($pdo, 'wa_fam_max', 3)) $o['stopped'] = 'needs a human to rewrite';
    if (!$deep) { $o['u_status'] = $on ? wa_mix_light_status($pdo, $twins) : ($twins ? 'off' : 'none'); return $o; }
    $env = wa_mix_env($pdo); $nums = []; foreach ($env['numbers'] as $id => $n) { $c = $env['caps'][$id] ?? ['left' => 0]; $nums[$id] = ['left' => (int)$c['left']]; }
    $pl = wa_mix_plan($pdo, ['extra' => array_merge($ex, ['mix' => true])] + $camp, $nums, 0, null, false, $env); $o['u_status'] = $on ? $pl['u_status'] : ($twins ? 'off' : 'none'); $o['target'] = $pl['target']; $o['achieved'] = $pl['achieved']; $o['shortfall'] = $on ? $pl['shortfall'] : '';
    $cnt = []; $q = $pdo->prepare("SELECT number_id, COALESCE(NULLIF(variant, ''), 'M') AS v, COUNT(*) AS n FROM wa_camp_items WHERE camp_id = ? AND status = 'sent' GROUP BY number_id, v"); $q->execute([(int)$camp['id']]); foreach ($q->fetchAll() as $r) $cnt[(int)$r['number_id']][$r['v'] === 'U' ? 'U' : 'M'] = (int)$r['n'];
    $mAv = function (int $nid) use ($pdo, $mId, $env, $camp) { $a = wa_tpl_avail($pdo, $mId, $nid, $env); if ($a['ok']) return $a; foreach (wa_fam_members($pdo, wa_fam_root(wa_mix_trow($pdo, $mId))) as $m) if ($m['line'] === 'M' && (int)$m['id'] !== $mId && !$m['relabelled_at']) { $b = wa_tpl_avail($pdo, (int)$m['id'], $nid, $env); if ($b['ok']) return $b + ['alt' => (int)$m['id']]; } return $a; };
    foreach ($pl['per_number'] as $nid => $p) {
        $ma = $mAv((int)$nid); $o['per_number'][] = ['id' => (int)$nid, 'label' => $p['label'], 'share' => $p['share'], 'health_share' => $p['health_share'], 'cap' => $p['cap'], 'why' => $p['why'], 'quality' => $p['quality'], 'm_ok' => $ma['ok'], 'm_why' => $ma['why'],
            'u_ok' => $p['u_state'] === 'on', 'u_state' => $p['u_state'], 'u_why' => $p['u_why'], 'u_name' => $p['u'] ? (string)$p['u']['name'] : '', 'sent_u' => (int)($cnt[$nid]['U'] ?? 0), 'sent_m' => (int)($cnt[$nid]['M'] ?? 0)];
    }
    foreach ($cnt as $nid => $c) if (!isset($pl['per_number'][$nid]) && isset($env['numbers'][$nid])) $o['per_number'][] = ['id' => (int)$nid, 'label' => wa_mix_numlabel($env, (int)$nid), 'share' => 0, 'health_share' => 0, 'cap' => 0, 'why' => '', 'quality' => '', 'm_ok' => false, 'm_why' => '', 'u_ok' => false, 'u_state' => 'none', 'u_why' => '', 'u_name' => '', 'sent_u' => (int)($c['U'] ?? 0), 'sent_m' => (int)($c['M'] ?? 0)];
    $o['variants'] = wa_mix_variants($pdo, (int)$camp['id']);
    // cost: a utility send counts as marketing when its message was re-labelled at or before the send
    $rm = (float)$cfg['rate_marketing']; $ru = (float)$cfg['rate_utility']; $u = 0; $uAsM = 0; $m = 0;
    $q = $pdo->prepare("SELECT COALESCE(NULLIF(i.variant, ''), 'M') AS v, i.sent_at, t.relabelled_at FROM wa_camp_items i LEFT JOIN wa_templates t ON t.id = i.tpl_used WHERE i.camp_id = ? AND i.status = 'sent'"); $q->execute([(int)$camp['id']]);
    foreach ($q->fetchAll() as $r) { if ($r['v'] !== 'U') { $m++; continue; } if ($r['relabelled_at'] && strtotime((string)$r['sent_at']) >= strtotime((string)$r['relabelled_at'])) { $uAsM++; $m++; } else $u++; }
    $o['cost'] = ['marketing_sends' => $m, 'utility_sends' => $u, 'relabelled_as_marketing' => $uAsM, 'rate_marketing' => $rm, 'rate_utility' => $ru, 'total' => round($m * $rm + $u * $ru, 2), 'saved' => round($u * ($rm - $ru), 2)];
    return $o;
}
function wa_mix_trow(PDO $pdo, int $id): array { $q = $pdo->prepare("SELECT * FROM wa_templates WHERE id = ?"); $q->execute([$id]); return $q->fetch() ?: ['id' => $id, 'family_id' => 0]; }
/** One word for the state of a template across accounts (like wa_tpl_status, plus re-labelled). */
function wa_mix_tpl_state(PDO $pdo, int $id, array $wabas): string
{
    $r = wa_mix_trow($pdo, $id); if (!empty($r['relabelled_at'])) return 'relabelled';
    $q = $pdo->prepare("SELECT status FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]); $st = []; foreach ($q->fetchAll() as $x) $st[] = strtoupper((string)$x['status']);
    if (!$st) return 'missing';
    if (in_array('APPROVED', $st, true)) return 'approved';   // approved on at least one account: the numbers of that account can use it
    foreach (['PENDING', 'IN_APPEAL', 'PENDING_DELETION', 'ERROR'] as $w) if (in_array($w, $st, true)) return 'waiting';
    if (in_array('PAUSED', $st, true) || in_array('DISABLED', $st, true) || in_array('FLAGGED', $st, true)) return 'paused';
    return in_array('REJECTED', $st, true) ? 'rejected' : 'waiting';
}
/** The state of the utility twin across all accounts, without looking at the numbers (cheap, for lists). */
function wa_mix_light_status(PDO $pdo, array $twins): string
{
    if (!$twins) return 'none'; $wabas = wa_wabas($pdo); $last = end($twins); $best = 'waiting';
    foreach (array_reverse($twins) as $tw) { if ($tw['relabelled_at']) continue; $s = wa_mix_tpl_state($pdo, (int)$tw['id'], $wabas); if ($s === 'approved') return 'approved'; if ($s === 'rejected') $best = 'rejected'; if ($s === 'paused') $best = 'paused'; }
    if ($last['relabelled_at']) return (int)$last['ver'] >= wa_mix_cfg($pdo, 'wa_fam_max', 3) ? 'stopped' : 'relabelled';
    return $best;
}
/** [utility sends, of which re-labelled before they were sent] of one campaign. */
function wa_mix_u_counts(PDO $pdo, int $campId): array
{
    try {
        $q = $pdo->prepare("SELECT COUNT(*) AS n, SUM(CASE WHEN t.relabelled_at IS NOT NULL AND i.sent_at >= t.relabelled_at THEN 1 ELSE 0 END) AS r FROM wa_camp_items i LEFT JOIN wa_templates t ON t.id = i.tpl_used WHERE i.camp_id = ? AND i.status = 'sent' AND i.variant = 'U'"); $q->execute([$campId]); $r = $q->fetch();
        return [(int)$r['n'], (int)$r['r']];
    } catch (PDOException $e) { return [0, 0]; }
}
/** For the "Start" step: how many of these people may get the utility version (asked or ordered in the last wa_mix_days days) and what that would cost. */
function wa_mix_estimate(PDO $pdo, array $send, int $mId, array $cfg): array
{
    $days = wa_mix_cfg($pdo, 'wa_mix_days', 30); $from = date('Y-m-d H:i:s', wa_mix_now() - $days * 86400); $n = 0; $twins = wa_mix_twins($pdo, $mId);
    foreach (array_chunk(array_column($send, 'phone'), 500) as $ch) {
        if (!$ch) continue; $in = implode(',', array_fill(0, count($ch), '?'));
        $q = $pdo->prepare("SELECT COUNT(*) FROM wa_contacts c WHERE c.phone IN ($in) AND c.optout = 0 AND (c.last_in_at >= ? OR c.phone IN (SELECT s.phone FROM wa_cust s WHERE s.n > 0 AND s.last_order >= ? AND s.phone IN ($in)))"); $q->execute(array_merge($ch, [$from, $from], $ch)); $n += (int)$q->fetchColumn();
    }
    $env = wa_mix_env($pdo); $w = 0; $sum = 0; foreach ($env['numbers'] as $id => $nm) { $l = max(1, (int)($env['caps'][$id]['left'] ?? 1)); $sh = wa_mix_share($pdo, $id, $mId, false, $env); $w += $l; $sum += $l * $sh['share']; } $share = $w ? $sum / $w : 0;
    $est = (int)floor($n * $share / 100);
    return ['has_twin' => (bool)$twins, 'eligible' => $n, 'days' => $days, 'share_now' => round($share), 'est_utility' => $est, 'est_saving' => round($est * ((float)$cfg['rate_marketing'] - (float)$cfg['rate_utility']), 2)];
}
/** Debug / "why 40%": every state row with the number's name, plus the latest changes. */
function wa_mix_dump(PDO $pdo, int $tplId = 0, int $numberId = 0): array
{
    wa_mix_tables($pdo); $env = wa_mix_env($pdo); $w = []; $p = []; if ($tplId) { $w[] = 'tpl_id = ?'; $p[] = $tplId; } if ($numberId) { $w[] = 'number_id = ?'; $p[] = $numberId; }
    if ($tplId > 0) foreach ($env['numbers'] as $nid => $n) { if ($numberId && $nid !== $numberId) continue; wa_mix_state_row($pdo, (int)$nid, $tplId); }   // one line per number, also for a number that cannot send now (it shows 0 and why)
    $q = $pdo->prepare("SELECT * FROM wa_mix_state" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY tpl_id, number_id"); $q->execute($p); $rows = [];
    foreach ($q->fetchAll() as $r) { $sh = wa_mix_share($pdo, (int)$r['number_id'], (int)$r['tpl_id'], false, $env); $rows[] = $r + ['label' => wa_mix_numlabel($env, (int)$r['number_id']), 'effective' => $sh['share'], 'safe_cap' => $sh['cap'], 'line' => $sh['why']]; }
    $l = $pdo->prepare("SELECT * FROM wa_mix_log" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT 40"); $l->execute($p);
    return ['state' => $rows, 'log' => $l->fetchAll(), 'settings' => ['days' => wa_mix_cfg($pdo, 'wa_mix_days', 30), 'start' => wa_mix_cfg($pdo, 'wa_mix_start', 10), 'batch' => wa_mix_cfg($pdo, 'wa_mix_batch', 40), 'ok_delivered' => wa_mix_cfg($pdo, 'wa_mix_ok_deliv', 97), 'ok_failed' => wa_mix_cfg($pdo, 'wa_mix_ok_fail', 2), 'bad_failed' => wa_mix_cfg($pdo, 'wa_mix_bad_fail', 5), 'settle_min' => wa_mix_cfg($pdo, 'wa_mix_settle_min', 120), 'family_max' => wa_mix_cfg($pdo, 'wa_fam_max', 3)]];
}

/** Adds the per-number chips, the utility twin state, the family and "what Claude learned" to a list of wa_tpl_public() rows. */
function wa_mix_decorate(PDO $pdo, array $list): array
{
    if (!wa_mix_tables($pdo)) return $list;
    $env = wa_mix_env($pdo); $wabas = wa_wabas($pdo); $by = []; foreach ($pdo->query("SELECT tpl_id, waba_id, status, reason, quality FROM wa_tpl_waba")->fetchAll() as $r) $by[(int)$r['tpl_id']][(string)$r['waba_id']] = $r;
    $rows = []; foreach ($pdo->query("SELECT id, family_id, version, relabelled_at, category_now, extra, name, category FROM wa_templates")->fetchAll() as $r) { $r['ex'] = wa_mix_extra($r); $rows[(int)$r['id']] = $r; }
    $tw = []; foreach ($rows as $r) { $u = (int)($r['ex']['utility_of'] ?? 0); if ($u > 0) $tw[$u][] = $r; }
    foreach ($tw as &$l) usort($l, fn($a, $b) => ((int)$a['version'] ?: (int)($a['ex']['utility_v'] ?? 1)) <=> ((int)$b['version'] ?: (int)($b['ex']['utility_v'] ?? 1))); unset($l);
    $learn = []; try { $q = $pdo->query("SELECT c.tpl_id, i.number_id, COUNT(*) AS n, SUM(CASE WHEN m.status IN ('delivered','read') OR i.last_status IN ('delivered','read') THEN 1 ELSE 0 END) AS d FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id LEFT JOIN wa_messages m ON m.wamid = i.wamid AND i.wamid <> '' WHERE i.variant = 'U' AND i.status = 'sent' AND i.sent_at <= '" . date('Y-m-d H:i:s', wa_mix_now() - wa_mix_cfg($pdo, 'wa_mix_settle_min', 120) * 60) . "' GROUP BY c.tpl_id, i.number_id");
        foreach ($q->fetchAll() as $r) $learn[(int)$r['tpl_id']][(int)$r['number_id']] = [(int)$r['n'], (int)$r['d']]; } catch (PDOException $e) { }
    $notes = []; foreach ($pdo->query("SELECT number_id, tpl_id, share, note FROM wa_mix_state")->fetchAll() as $r) $notes[(int)$r['tpl_id']][(int)$r['number_id']] = $r;
    $stateOf = function (int $id) use ($by, $env, $rows) {   // chips: one per number
        $chips = []; foreach ($env['numbers'] as $nid => $n) { $w = $by[$id][$n['waba']] ?? null; $st = $w ? strtoupper((string)$w['status']) : ''; $s = !$w ? 'missing' : ($st === 'APPROVED' ? 'ok' : ($st === 'REJECTED' ? 'rejected' : (in_array($st, ['PAUSED', 'DISABLED', 'FLAGGED'], true) ? 'paused' : ($st === 'ERROR' ? 'error' : 'waiting'))));
            if ($s === 'ok' && !empty($rows[$id]['relabelled_at'])) $s = 'relabelled'; $chips[] = ['number_id' => (int)$nid, 'label' => $n['label_txt'], 'waba' => $n['waba'], 'state' => $s, 'reason' => $w ? (string)$w['reason'] : '', 'quality' => $w ? (string)($w['quality'] ?? '') : '']; }
        return $chips; };
    foreach ($list as &$t) {
        $id = (int)$t['id']; $r = $rows[$id] ?? null; $t['matrix'] = $stateOf($id); $t['relabelled_at'] = $r && $r['relabelled_at'] ? (string)$r['relabelled_at'] : null; $t['category_now'] = $r ? (string)$r['category_now'] : '';
        $t['family_id'] = $r ? ((int)$r['family_id'] ?: $id) : $id; $t['version'] = $r ? ((int)$r['version'] ?: (int)($r['ex']['utility_v'] ?? 1)) : 1; $t['is_twin'] = (int)($t['utility_of'] ?? $r['ex']['utility_of'] ?? 0) > 0;
        $twins = []; foreach ($tw[$id] ?? [] as $x) { $xid = (int)$x['id']; $twins[] = ['id' => $xid, 'name' => (string)$x['name'], 'status' => wa_mix_tpl_state($pdo, $xid, $wabas), 'category_now' => (string)$x['category_now'], 'relabelled_at' => $x['relabelled_at'] ? (string)$x['relabelled_at'] : null, 'version' => (int)$x['version'] ?: (int)($x['ex']['utility_v'] ?? 1), 'matrix' => $stateOf($xid)]; }
        $t['twins'] = $twins; $last = $twins ? end($twins) : null; $ok = array_filter($twins, fn($x) => $x['status'] === 'approved');
        $t['utility_state'] = $t['is_twin'] ? '' : (!$twins ? 'none' : ($ok ? 'approved' : ($last['status'] === 'relabelled' ? ($last['version'] >= wa_mix_cfg($pdo, 'wa_fam_max', 3) ? 'stopped' : 'relabelled') : ($last['status'] === 'rejected' ? 'rejected' : ($last['status'] === 'paused' ? 'paused' : 'waiting')))));
        $t['stop'] = ($t['utility_state'] === 'stopped') ? 'needs a human to rewrite' : '';
        $ln = []; foreach ($learn[$id] ?? [] as $nid => [$n, $d]) if ($n >= 5) $ln[] = 'Utility version delivered ' . round($d * 100 / $n) . '% on ' . ($env['numbers'][$nid]['label_txt'] ?? 'Number ' . $nid) . ' (' . $n . ' sends)';
        foreach ($notes[$id] ?? [] as $nid => $s) if (!isset($learn[$id][$nid]) && (int)$s['share'] !== wa_mix_cfg($pdo, 'wa_mix_start', 10)) $ln[] = ($env['numbers'][$nid]['label_txt'] ?? 'Number ' . $nid) . ': utility ' . $s['share'] . '% - ' . $s['note'];
        $t['learned'] = implode(' · ', array_slice($ln, 0, 3));
        $fam = []; if ($r) foreach (wa_fam_members($pdo, $t['family_id']) as $m) { if ((int)$m['id'] === $id && false) continue; $fam[] = ['id' => (int)$m['id'], 'line' => $m['line'], 'version' => (int)$m['ver'], 'status' => wa_mix_tpl_state($pdo, (int)$m['id'], $wabas), 'name' => (string)$m['name']]; }
        $t['family'] = count($fam) > 1 ? $fam : [];
    }
    unset($t); return $list;
}

// ---------------------------------------------------------------------------------------------------------------- Meta's limits and the auto-submit engine
/** May this WhatsApp account take one more template right now? Counts what WE created in the last hour / day and the total at Meta. */
function wa_tpl_budget(PDO $pdo, string $waba): array
{
    wa_mix_tables($pdo); $now = wa_mix_now(); $hc = wa_mix_cfg($pdo, 'wa_tpl_hour_cap', 100000); $dc = wa_mix_cfg($pdo, 'wa_tpl_day_cap', 100000); $tc = wa_mix_cfg($pdo, 'wa_tpl_total_cap', 100000);
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_subs WHERE waba_id = ? AND at >= ?"); $q->execute([$waba, date('Y-m-d H:i:s', $now - 3600)]); $h = (int)$q->fetchColumn(); $q->execute([$waba, date('Y-m-d H:i:s', $now - 86400)]); $d = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_waba WHERE waba_id = ? AND meta_id <> ''"); $q->execute([$waba]); $t = (int)$q->fetchColumn();
    $why = $h >= $hc ? 'hourly limit reached (' . $hc . ' templates an hour per account)' : ($d >= $dc ? 'daily limit reached (' . $dc . ' a day per account)' : ($t >= $tc ? 'this WhatsApp account already has ' . $t . ' templates - clean up old rejected versions' : ''));
    return ['ok' => $why === '', 'why' => $why, 'hour' => $h, 'day' => $d, 'total' => $t, 'hour_cap' => $hc, 'day_cap' => $dc, 'total_cap' => $tc];
}
/** One submission with all the guards. Returns [ok, note]. $budget['left'] counts the submissions this run may still make. */
function wa_tpl_try_create(PDO $pdo, array $tpl, string $waba, array &$budget): array
{
    $queue = function () use ($pdo, $tpl) { try { if (empty(wa_mix_extra($tpl)['queued'])) wa_mix_extra_set($pdo, (int)$tpl['id'], ['queued' => 1]); } catch (Throwable $e) { } };   // not sent for lack of room: the engine picks it up later
    if (($budget['left'] ?? 0) <= 0) { $queue(); return [false, 'over the limit for one run - continues in the next minute']; }
    $b = wa_tpl_budget($pdo, $waba); if (!$b['ok']) { $queue(); return [false, $b['why']]; }
    $budget['left']--; $budget['made'] = ($budget['made'] ?? 0) + 1; return wa_tpl_create_at($pdo, $tpl, $waba);
}
function wa_tpl_live(PDO $pdo, array $r, array $rowsByWaba): bool
{
    $x = wa_mix_extra($r); if (!$rowsByWaba && empty($x['queued'])) return false; if (!empty($x['unsupported'])) return false; if (!empty($r['relabelled_at'])) return false;   // a saved draft is never sent by itself; a message that only waited for room (queued) is
    $hf = (string)$r['header_format']; if (in_array($hf, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) && (int)($r['header_lib_id'] ?? 0) <= 0) return false;
    if (!$rowsByWaba) return true;
    $alive = false; foreach ($rowsByWaba as $w) { $s = strtoupper((string)$w['status']); if (in_array($s, ['PAUSED', 'DISABLED', 'FLAGGED'], true)) return false; if ($s !== 'REJECTED') $alive = true; }
    return $alive;
}
/**
 * The background step (called by the campaign tick, throttled): 1. submits every message that is missing in a WhatsApp account (a new number / account gets all of them),
 * 2. retries submissions that failed for a passing reason (back-off 5 min, 10, 20 ... up to 6 h, 6 tries), 3. makes alternative versions where there is trouble, 4. makes utility twins that are still missing.
 * Max wa_tpl_tick_max (8) submissions per run, never over Meta's limits. Never deletes anything. Returns counts.
 */
function wa_tpl_autosubmit_tick(PDO $pdo, bool $force = false): array
{
    $res = ['submitted' => 0, 'retried' => 0, 'alternatives' => 0, 'twins' => 0, 'skipped' => '', 'waiting_limit' => 0];
    if (!function_exists('wa_tpl_tables') || !wa_tpl_tables($pdo) || !wa_mix_tables($pdo)) return $res;
    if (wa_mix_cfg($pdo, 'wa_tpl_auto', 1) != 1) { $res['skipped'] = 'auto-submit is switched off'; return $res; }
    $wabas = wa_wabas($pdo); if (!$wabas) { $res['skipped'] = 'no WhatsApp account'; return $res; }
    try { $got = (int)$pdo->query("SELECT GET_LOCK('wa_tpl_auto', 0)")->fetchColumn(); } catch (Throwable $e) { $got = 0; } if ($got !== 1) { $res['skipped'] = 'busy'; return $res; }
    try {
        $last = (int)strtotime((string)setting_get($pdo, 'wa_tpl_auto_at', '')); if (!$force && $last > wa_mix_now() - 50) { $res['skipped'] = 'throttled'; return $res; }
        setting_set($pdo, 'wa_tpl_auto_at', date('Y-m-d H:i:s', wa_mix_now())); $budget = ['left' => wa_mix_cfg($pdo, 'wa_tpl_tick_max', 40), 'made' => 0]; $now = wa_mix_now();
        $by = []; foreach ($pdo->query("SELECT * FROM wa_tpl_waba")->fetchAll() as $w) $by[(int)$w['tpl_id']][(string)$w['waba_id']] = $w;
        $rows = $pdo->query("SELECT * FROM wa_templates ORDER BY used DESC, id")->fetchAll();
        // 1 + 2: missing pairs and failed submissions (approved ones first)
        $todo = [];
        foreach ($rows as $r) {
            $id = (int)$r['id']; $have = $by[$id] ?? []; if (!wa_tpl_live($pdo, $r, $have)) continue; $okAny = false; foreach ($have as $w) if (strtoupper((string)$w['status']) === 'APPROVED') $okAny = true;
            foreach ($wabas as $wb) {
                $w = $have[$wb] ?? null;
                if (!$w) $todo[] = [$okAny ? 0 : 1, $r, $wb, false];
                elseif (strtoupper((string)$w['status']) === 'ERROR' && (int)($w['tries'] ?? 0) < 6 && ($w['next_try'] === null || strtotime((string)$w['next_try']) <= $now)) $todo[] = [$okAny ? 0 : 1, $r, $wb, true];
            }
        }
        usort($todo, fn($a, $b) => $a[0] <=> $b[0]);
        foreach ($todo as [$_, $r, $wb, $retry]) {
            if ($budget['left'] <= 0) { $res['waiting_limit']++; continue; }
            $t = wa_tpl_row($pdo, (int)$r['id']); if (!$t) continue; [$ok, $why] = wa_tpl_try_create($pdo, $t, $wb, $budget);
            if ($ok) { if ($retry) $res['retried']++; else $res['submitted']++; } elseif (strpos($why, 'limit') !== false || strpos($why, 'templates') !== false) $res['waiting_limit']++;
        }
        // 3: alternatives where there is trouble
        $res['alternatives'] = wa_fam_scan($pdo, $budget);
        // 4: utility twins that are still missing
        $res['twins'] = wa_util_mix_on($pdo) ? wa_mix_twin_backfill($pdo, $budget) : 0;   // utility twins of offers: only when the old mix is switched on
        if (function_exists('wa_util_auto')) { try { $res['twins'] += wa_util_auto($pdo); } catch (Throwable $e) { } }   // ✨ 3 utility versions for every new marketing message (owner's campaign utility %)
        if ($res['submitted'] || $res['retried'] || $res['alternatives'] || $res['twins']) wa_log($pdo, 'template', true, 'Auto-submit: ' . $res['submitted'] . ' new, ' . $res['retried'] . ' retried, ' . $res['alternatives'] . ' alternative versions, ' . $res['twins'] . ' utility twins');
    } catch (Throwable $e) { wa_log($pdo, 'template', false, 'Auto-submit error: ' . $e->getMessage()); $res['skipped'] = 'error'; }
    try { $pdo->query("SELECT RELEASE_LOCK('wa_tpl_auto')"); } catch (Throwable $e) { }
    return $res;
}
/** Numbers for the "Auto-approval" card. */
function wa_tpl_auto_status(PDO $pdo): array
{
    wa_mix_tables($pdo); $wabas = wa_wabas($pdo); $now = wa_mix_now(); $day = date('Y-m-d H:i:s', $now - 86400);
    $q = $pdo->prepare("SELECT COUNT(*) FROM wa_tpl_subs WHERE at >= ?"); $q->execute([$day]); $subDay = (int)$q->fetchColumn();
    $st = ['PENDING' => 0, 'REJECTED' => 0, 'APPROVED' => 0, 'ERROR' => 0, 'PAUSED' => 0]; foreach ($pdo->query("SELECT UPPER(status) AS s, COUNT(*) AS n FROM wa_tpl_waba GROUP BY UPPER(status)")->fetchAll() as $r) { $k = in_array($r['s'], ['DISABLED', 'FLAGGED'], true) ? 'PAUSED' : $r['s']; $st[$k] = ($st[$k] ?? 0) + (int)$r['n']; }
    $alts = (int)$pdo->query("SELECT COUNT(*) FROM wa_templates WHERE (version > 1 OR extra LIKE '%\"retry_of\"%')")->fetchColumn();
    $missing = 0; $have = []; foreach ($pdo->query("SELECT tpl_id, waba_id FROM wa_tpl_waba")->fetchAll() as $r) $have[(int)$r['tpl_id'] . '|' . $r['waba_id']] = 1;
    foreach ($pdo->query("SELECT id FROM wa_templates WHERE source = 'meta' OR id IN (SELECT tpl_id FROM wa_tpl_waba)")->fetchAll() as $r) foreach ($wabas as $w) if (!isset($have[(int)$r['id'] . '|' . $w])) $missing++;
    $stopped = []; foreach ($pdo->query("SELECT id, name, extra FROM wa_templates WHERE extra LIKE '%fam_stop%'")->fetchAll() as $r) { $x = wa_mix_extra($r); if (!empty($x['fam_stop'])) $stopped[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'why' => 'needs a human to rewrite']; }
    $bud = []; foreach ($wabas as $w) $bud[] = ['waba' => $w] + wa_tpl_budget($pdo, $w);
    return ['on' => wa_mix_cfg($pdo, 'wa_tpl_auto', 1) == 1, 'accounts' => count($wabas), 'submitted_today' => $subDay, 'waiting' => $st['PENDING'] + $st['ERROR'], 'approved' => $st['APPROVED'], 'rejected' => $st['REJECTED'], 'paused' => $st['PAUSED'], 'retrying' => $st['ERROR'], 'alternatives_made' => $alts, 'missing_submissions' => $missing,
        'needs_human' => $stopped, 'limits' => $bud, 'last_run' => (string)setting_get($pdo, 'wa_tpl_auto_at', ''), 'twin_backfill' => wa_mix_cfg($pdo, 'wa_tpl_twin_backfill', 0) == 1];
}

// ---------------------------------------------------------------------------------------------------------------- making twins and alternatives
function wa_mix_name(string $base, string $suffix): string { return substr($base, 0, 60 - strlen($suffix)) . $suffix; }
/**
 * Makes the utility twin (version $ver) of a marketing message and sends it to every account. $ver 1 = the normal rewrite, 2 and 3 = stricter and stricter. Idempotent: the same (message, version) is made once.
 * Returns ['ok', 'id', 'name', 'errors', 'made'] .
 */
function wa_mix_make_twin(PDO $pdo, array $m, int $ver, int $retryOf, array &$budget, int $by = 0, ?array $ready = null): array
{
    $res = ['ok' => false, 'id' => 0, 'name' => '', 'errors' => [], 'made' => false];
    foreach (wa_mix_twins($pdo, (int)$m['id']) as $tw) if ((int)$tw['ver'] === $ver) { $res['ok'] = true; $res['id'] = (int)$tw['id']; $res['name'] = (string)$tw['name']; return $res; }
    $src = $m; $src['header_lib_id'] = (int)($m['header_lib_id'] ?? 0); [$t, $err] = wa_tpl_clean($src, true); if ($err !== '') { $res['errors'][] = $err; return $res; }
    $u = $ready ?: wa_tpl_utility_build($t, max(0, $ver - 1))['draft']; $u['category'] = 'UTILITY'; $u['language'] = $t['language']; $u['name'] = wa_mix_name((string)$m['name'], $ver > 1 ? '_u' . $ver : '_u');
    [$cu, $uerr] = wa_tpl_clean($u); if ($uerr === '') $uerr = wa_tpl_media_check($pdo, $cu); if ($uerr !== '') { $res['errors'][] = $uerr; return $res; }
    $root = wa_fam_root($m); $cu['family_id'] = $root; $cu['version'] = $ver; $cu['extra'] = array_merge(is_array($cu['extra']) ? $cu['extra'] : [], ['utility_of' => (int)$m['id'], 'utility_v' => $ver] + ($retryOf ? ['retry_of' => $retryOf] : []));
    if ($cu['folder'] === '') $cu['folder'] = (string)($m['folder'] ?? '');
    $cu['id'] = wa_tpl_insert($pdo, $cu, $by, (string)($m['purpose'] ?? ''), 'Utility version' . ($ver > 1 ? ' ' . $ver : '') . ' of ' . $m['name']);
    $q = $pdo->prepare("SELECT name FROM wa_templates WHERE id = ?"); $q->execute([$cu['id']]); $cu['name'] = (string)$q->fetchColumn();
    wa_mix_extra_set($pdo, (int)$m['id'], ['utility_id' => $cu['id'], 'need_twin' => null]);
    $row = wa_tpl_row($pdo, $cu['id']); $res['ok'] = true; $res['id'] = (int)$cu['id']; $res['name'] = $cu['name']; $res['made'] = true;
    foreach (wa_wabas($pdo) as $w) { [$ok, $why] = wa_tpl_try_create($pdo, $row, $w, $budget); if (!$ok) $res['errors'][] = $why; }
    wa_log($pdo, 'template', !$res['errors'], 'Utility version ' . $ver . ' of ' . $m['name'] . ' made: ' . $cu['name'] . ($res['errors'] ? ' - ' . $res['errors'][0] : ''));
    return $res;
}
/** A fresh marketing rewrite of a message that had trouble (Claude first, plain tidy-up rules when Claude cannot). */
function wa_fam_market_rewrite(array $t, int $ver, string $reason): array
{
    $sch = ['type' => 'OBJECT', 'properties' => ['body' => ['type' => 'STRING'], 'header_text' => ['type' => 'STRING'], 'footer' => ['type' => 'STRING'], 'quick_replies' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']]], 'required' => ['body']];
    $sys = "You rewrite ONE WhatsApp marketing template of a perfume shop (TM Perfume House, Surat) because the first version had trouble with Meta (" . $reason . "). Say the same thing with new words: calmer, shorter, no capital-letter shouting, no more than one emoji, no exaggeration, no pressure words. KEEP exactly the same variables {{1}}, {{2}} ... in the same order and meaning, never at the very start or the very end of the text. Same language and friendly Hinglish tone. Never write real designer brand names. " . (defined('WA_TPL_RULES') ? WA_TPL_RULES : '');
    [$ok, $v] = wa_tpl_model($sys, "THE MESSAGE:\n" . json_encode(['header' => (string)($t['header_text'] ?? ''), 'body' => $t['body'], 'footer' => (string)($t['footer'] ?? ''), 'buttons' => array_map(fn($b) => (string)($b['text'] ?? ''), (array)($t['buttons'] ?? []))], JSON_UNESCAPED_UNICODE), $sch, 2000);
    $d = $t; $d['examples'] = $t['examples'] ?? []; $body = $t['body'];
    if ($ok && is_array($v) && trim((string)($v['body'] ?? '')) !== '' && count(wa_tpl_vars((string)$v['body'])) === count(wa_tpl_vars((string)$t['body']))) { $body = trim((string)$v['body']); if (trim((string)($v['footer'] ?? '')) !== '' && (string)($t['footer'] ?? '') !== '') $d['footer'] = mb_substr(trim((string)$v['footer']), 0, 60); }
    else {   // tidy-up rules
        $body = preg_replace_callback('/\b[\p{Lu}]{4,}\b/u', fn($m) => mb_convert_case(mb_strtolower($m[0]), MB_CASE_TITLE, 'UTF-8'), $body) ?? $body; $body = preg_replace('/([!?.])\1+/u', '$1', $body) ?? $body;
        $n = 0; $body = preg_replace_callback('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', function ($m) use (&$n) { return ++$n > 1 ? '' : $m[0]; }, $body) ?? $body; $body = trim(preg_replace('/ {2,}/', ' ', $body) ?? $body);
        if ($body === trim($t['body'])) $body = rtrim($body) . ($ver > 2 ? "\n\nReply STOP if you do not want these messages." : "\n\nAap chahein to bas reply karein, hum madad karenge.");
    }
    $d['body'] = $body; $d['name'] = wa_mix_name(preg_replace('/_v\d+$/', '', (string)$t['name']), '_v' . $ver); $d['category'] = 'MARKETING'; unset($d['id']);
    return $d;
}
/** Why a message needs an alternative right now ('' = fine). */
function wa_fam_trouble(array $r, array $have): string
{
    foreach ($have as $w) { $s = strtoupper((string)$w['status']);
        if ($s === 'REJECTED' && (string)$w['meta_id'] !== '' ) return 'rejected by Meta' . ((string)$w['reason'] !== '' ? ' (' . mb_substr((string)$w['reason'], 0, 60) . ')' : '');
        if ($s === 'REJECTED' && (string)$w['reason'] !== '' && !preg_match('/reach|answer|WA_APP_ID|WA_TOKEN|photo|video|PDF|file|library|sample|upload|limit/i', (string)$w['reason'])) return 'rejected by Meta (' . mb_substr((string)$w['reason'], 0, 60) . ')';
        if (in_array($s, ['PAUSED', 'DISABLED', 'FLAGGED'], true)) return 'paused by Meta';
        if (in_array(strtoupper((string)($w['quality'] ?? '')), ['YELLOW', 'RED'], true)) return 'quality rating fell to ' . (strtoupper((string)$w['quality']) === 'RED' ? 'Low' : 'Medium'); }
    if (!empty($r['relabelled_at'])) return 'Meta re-labelled it as marketing';
    return '';
}
/** Looks for messages with trouble and makes the next version (v2, v3 ... max wa_fam_max per line). Returns how many alternatives were made. */
function wa_fam_scan(PDO $pdo, array &$budget): int
{
    $max = wa_mix_cfg($pdo, 'wa_fam_max', 3); $made = 0; $wabas = wa_wabas($pdo); $by = [];
    foreach ($pdo->query("SELECT * FROM wa_tpl_waba")->fetchAll() as $w) $by[(int)$w['tpl_id']][(string)$w['waba_id']] = $w;
    $all = $pdo->query("SELECT * FROM wa_templates ORDER BY id")->fetchAll(); $rows = []; foreach ($all as $r) { $r['ex'] = wa_mix_extra($r); $r['ver'] = (int)$r['version'] ?: (int)($r['ex']['utility_v'] ?? 1); $r['twin_of'] = (int)($r['ex']['utility_of'] ?? 0); $rows[(int)$r['id']] = $r; }
    $busyNums = []; try { $q = $pdo->query("SELECT DISTINCT i.number_id, COALESCE(NULLIF(i.tpl_used, 0), c.tpl_id) AS tid FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE i.status = 'sent' AND i.sent_at >= '" . date('Y-m-d H:i:s', wa_mix_now() - 3 * 86400) . "'");
        $env = wa_mix_env($pdo); foreach ($q->fetchAll() as $r) { $h = $env['health'][(int)$r['number_id']] ?? null; if ($h && in_array(strtoupper((string)$h['quality']), ['YELLOW', 'RED'], true)) $busyNums[(int)$r['tid']] = 'the number ' . wa_mix_numlabel($env, (int)$r['number_id']) . ' is at ' . wa_mix_q_name((string)$h['quality']) . ' quality'; } } catch (PDOException $e) { }
    // latest version of every line: marketing line per family (twin_of = 0), utility line per marketing message
    $lines = []; foreach ($rows as $r) { if ($r['twin_of'] > 0) $k = 'U' . $r['twin_of']; else { if (in_array(strtoupper((string)$r['category']), ['UTILITY'], true) && (int)$r['family_id'] === 0 && (int)$r['version'] <= 1) continue; $k = 'M' . wa_fam_root($r); } $lines[$k][] = $r; }
    foreach ($lines as $k => $members) {
        usort($members, fn($a, $b) => $a['ver'] <=> $b['ver'] ?: (int)$a['id'] <=> (int)$b['id']); $L = end($members); $id = (int)$L['id']; $have = $by[$id] ?? [];
        if (!$have || (string)$L['source'] === '' ) continue; $why = wa_fam_trouble($L, $have) ?: ($busyNums[$id] ?? ''); if ($why === '') continue;
        if ($k[0] === 'U' && !wa_util_mix_on($pdo)) continue;                                              // no new utility versions of offers
        if ($k[0] === 'M' && strtoupper((string)$L['category']) !== 'MARKETING') continue;                 // order updates and other plain utility messages: only shown, not rewritten
        if (count($members) >= $max) { if (empty($L['ex']['fam_stop'])) { wa_mix_extra_set($pdo, $id, ['fam_stop' => ['at' => date('Y-m-d H:i:s', wa_mix_now()), 'why' => $why]]); wa_log($pdo, 'template', false, $L['name'] . ': ' . $why . ' - already ' . count($members) . ' versions, needs a human to rewrite'); } continue; }
        if ($budget['left'] < max(1, count($wabas))) continue;
        $ver = (int)$L['ver'] + 1;
        if ($k[0] === 'U') {
            $mRow = wa_tpl_row($pdo, (int)$L['twin_of']); if (!$mRow) continue; $r = wa_mix_make_twin($pdo, $mRow, $ver, $id, $budget); if ($r['made']) { $made++; wa_mix_extra_set($pdo, $id, ['next' => $r['id']]); }
        } else {
            $row = wa_tpl_row($pdo, $id); if (!$row) continue; $dup = false; foreach ($members as $m) if ((int)$m['ver'] === $ver) $dup = true; if ($dup) continue;
            $d = wa_fam_market_rewrite($row, $ver, $why); [$cd, $err] = wa_tpl_clean($d); if ($err === '') $err = wa_tpl_media_check($pdo, $cd); if ($err !== '') { wa_log($pdo, 'template', false, 'Alternative of ' . $L['name'] . ' could not be made: ' . $err); continue; }
            $cd['family_id'] = wa_fam_root($L); $cd['version'] = $ver; $cd['extra'] = array_merge(is_array($cd['extra']) ? $cd['extra'] : [], ['alt_of' => $id, 'need_twin' => 1]);
            $cd['id'] = wa_tpl_insert($pdo, $cd, 0, (string)$L['purpose'], 'Version ' . $ver . ' of ' . $L['name'] . ' (' . $why . ')'); $nrow = wa_tpl_row($pdo, $cd['id']); $made++;
            foreach ($wabas as $w) wa_tpl_try_create($pdo, $nrow, $w, $budget);
            wa_log($pdo, 'template', true, 'Alternative marketing version made for ' . $L['name'] . ' (' . $why . '): ' . $nrow['name']);
        }
    }
    return $made;
}
/** Marketing messages that still have no utility twin get one (new versions always; old messages only when wa_tpl_twin_backfill = 1). */
function wa_mix_twin_backfill(PDO $pdo, array &$budget): int
{
    $made = 0; $all = wa_mix_cfg($pdo, 'wa_tpl_twin_backfill', 0) == 1; $wabas = wa_wabas($pdo); $auto = wa_mix_cfg($pdo, 'wa_tpl_auto_twin', 1) == 1; if (!$auto) return 0;
    $have = []; foreach ($pdo->query("SELECT extra FROM wa_templates WHERE extra LIKE '%\"utility_of\"%'")->fetchAll() as $r) { $u = (int)(wa_mix_extra($r)['utility_of'] ?? 0); if ($u) $have[$u] = true; }
    $st = []; foreach ($pdo->query("SELECT tpl_id, status FROM wa_tpl_waba")->fetchAll() as $w) $st[(int)$w['tpl_id']][] = strtoupper((string)$w['status']);
    foreach ($pdo->query("SELECT * FROM wa_templates WHERE category = 'MARKETING' ORDER BY used DESC, id")->fetchAll() as $r) {
        if ($budget['left'] < max(1, count($wabas)) || $made >= 2) break; $id = (int)$r['id']; $x = wa_mix_extra($r); if (isset($have[$id]) || (int)($x['utility_of'] ?? 0) > 0 || !empty($x['unsupported'])) continue;
        $s = $st[$id] ?? []; if (!$s || count(array_diff($s, ['REJECTED'])) === 0) continue;
        if (empty($x['need_twin']) && !$all) continue; if (!wa_tpl_sendable(wa_tpl_row($pdo, $id) ?: $r)) continue;
        $row = wa_tpl_row($pdo, $id); if (!$row) continue; $res = wa_mix_make_twin($pdo, $row, 1, 0, $budget); if ($res['made']) $made++; elseif (!$res['ok']) { wa_mix_extra_set($pdo, $id, ['need_twin' => null, 'twin_error' => mb_substr((string)($res['errors'][0] ?? 'error'), 0, 120)]); }
    }
    return $made;
}

}   // function_exists guard
