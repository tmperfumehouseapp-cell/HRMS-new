<?php
/**
 * wa_fu_coach.php — 🔁 the Follow-ups section of Learning & Improvement (learning.html → Follow-ups). Admin / HR.
 *   The owner writes ONE general summary ("if they ask the price and go quiet, nudge after 5 min, then send best sellers after 2 hours…").
 *   Claude reads it together with everything already taught (selling rules, store facts, price chart, limits, Lists) and builds the WHOLE plan:
 *   situations → timeline steps (minutes / hours; free WhatsApp messages inside 24 h, pre-made templates after 24 h) → every text → limits.
 *   It answers with "what I understood" + questions; the owner corrects in plain words; Claude rebuilds; "Save the plan" writes it into the
 *   follow-up engine (wa_follow.php, lib_facts 'fuplans'), the plan's own limits (setting fu_plan_limits) and the step templates (sent to Meta on request).
 *
 *   fu.coach_get                    the conversation so far, the saved plan (+ per-step numbers and template status), limits, mode, other follow-up engines
 *   fu.coach_ask    POST {text, reset?}        add the owner's message (summary or correction) → Claude's new proposal (nothing is saved)
 *   fu.coach_apply  POST {keys?}               save the proposal (all situations, or only these keys) as the live plan + its limits
 *   fu.coach_limits POST {max, day_max, gap_min, quiet_from, quiet_to}   the follow-up limits (override the general Teach limits for follow-ups)
 *   fu.coach_tpl    POST {sid?, n?}            send the after-24 h step templates to Meta (one step, or every step that has none yet)
 *   fu.coach_clear  POST                       start the conversation again (the saved plan stays)
 * Loaded by wa_more.php (after wa_brain.php). PHP 7.4 safe.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

if (!function_exists('fuc_get')) {

function fuc_get(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'fu_coach', ''), true); return is_array($j) ? $j + ['turns' => [], 'proposal' => null] : ['turns' => [], 'proposal' => null]; }
function fuc_put(PDO $pdo, array $c): void { $c['turns'] = array_slice((array)($c['turns'] ?? []), -16); setting_set($pdo, 'fu_coach', json_encode($c, JSON_UNESCAPED_UNICODE)); }
function fuc_plan_limits(PDO $pdo): array { $j = json_decode((string)setting_get($pdo, 'fu_plan_limits', ''), true); return is_array($j) ? $j : []; }
function fuc_hm(int $m): string { $m = (($m % 1440) + 1440) % 1440; $h = intdiv($m, 60); return ($h % 12 ?: 12) . ':' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . ($h < 12 ? ' am' : ' pm'); }
/** "Free message" until 23.5 h after our last message; after that only an approved template can reach the customer. */
function fuc_ch(float $afterH): string { return $afterH >= 23.5 ? 'tpl' : 'free'; }
/** The saved plan in the shape the screen uses: situations + per-step numbers (sent / replied, 30 days) + template status. */
function fuc_plan_view(PDO $pdo): array
{
    $P = function_exists('fu_plans_public') ? fu_plans_public($pdo) : ['situations' => [], 'mode' => 'off', 'limits' => []];
    $since = date('Y-m-d H:i:s', time() - 30 * 86400); $ev = [];
    try { $q = $pdo->prepare("SELECT sid, step, phone, sent_at FROM wa_fu_ev WHERE status = 'sent' AND sent_at >= ?"); $q->execute([$since]);
        foreach ($q->fetchAll() as $r) { $k = $r['sid'] . '|' . $r['step']; $ev[$k]['sent'] = ($ev[$k]['sent'] ?? 0) + 1; if (fu_replied($pdo, (string)$r['phone'], (string)$r['sent_at'])) $ev[$k]['replied'] = ($ev[$k]['replied'] ?? 0) + 1; } } catch (Throwable $e) { }
    $tplSt = function (int $id) use ($pdo) { if ($id <= 0) return ''; try { $q = $pdo->prepare("SELECT status FROM wa_tpl_waba WHERE tpl_id = ?"); $q->execute([$id]); $s = array_column($q->fetchAll(), 'status'); if (in_array('APPROVED', $s, true)) return 'APPROVED'; if (in_array('REJECTED', $s, true)) return 'REJECTED'; return $s ? 'PENDING' : 'PENDING'; } catch (Throwable $e) { return ''; } };
    foreach ($P['situations'] as &$s) foreach ($s['steps'] as $i => &$x) { $k = $s['id'] . '|' . $i; $x['sent'] = (int)($ev[$k]['sent'] ?? 0); $x['replied'] = (int)($ev[$k]['replied'] ?? 0); $x['ch'] = $x['ch'] ?? fuc_ch((float)$x['after_h']); $x['tpl_status'] = $tplSt((int)($x['tpl_id'] ?? 0)); }
    unset($s, $x);
    return $P;
}
/** Everything the owner taught, as one block for Claude (store facts + limits + the names of the Lists + the current plan). */
function fuc_context(PDO $pdo): string
{
    $o = ''; if (function_exists('teach_facts_prompt')) { [$f] = teach_facts_prompt($pdo, []); $o .= $f . "\n"; }
    if (function_exists('teach_limits_text')) $o .= teach_limits_text($pdo) . "\n";
    try { $L = $pdo->query("SELECT name FROM wa_lists ORDER BY grp, name LIMIT 30")->fetchAll(PDO::FETCH_COLUMN); if ($L) $o .= "READY PERFUME LISTS (a step may say: send the “…” list): " . implode(' · ', $L) . "\n"; } catch (Throwable $e) { }
    $P = function_exists('fu_plans_get') ? fu_plans_get($pdo) : ['situations' => []];
    if ($P['situations']) { $o .= "THE FOLLOW-UP PLAN THAT EXISTS NOW (keep what still fits, change what the owner asks):\n";
        foreach ($P['situations'] as $s) { $o .= '- [' . ($s['key'] ?? preg_replace('/^f_/', '', $s['id'])) . '] ' . $s['title'] . ($s['kind'] === 'stop' ? ' (STOP)' : '') . ': ' . $s['desc'] . "\n";
            foreach ($s['steps'] as $x) $o .= '    after ' . round($x['after_h'] * 60) . ' min: ' . mb_substr(str_replace("\n", ' ', (string)$x['text']), 0, 140) . "\n"; } }
    $L = function_exists('fu_limits') ? fu_limits($pdo) : null; if ($L) $o .= "CURRENT FOLLOW-UP LIMITS: max {$L['max']} per quiet customer, max {$L['day_max']} a day, at least {$L['gap_min']} min apart, quiet hours " . fuc_hm($L['quiet'][0]) . '–' . fuc_hm($L['quiet'][1]) . "\n";
    return $o;
}
function fuc_schema(): array
{
    $step = ['type' => 'OBJECT', 'properties' => ['after_min' => ['type' => 'INTEGER'], 'text' => ['type' => 'STRING'], 'template' => ['type' => 'STRING'], 'category' => ['type' => 'STRING'], 'why' => ['type' => 'STRING']], 'required' => ['after_min', 'text', 'template', 'category', 'why']];
    $sit = ['type' => 'OBJECT', 'properties' => ['key' => ['type' => 'STRING'], 'title' => ['type' => 'STRING'], 'when' => ['type' => 'STRING'], 'priority' => ['type' => 'INTEGER'], 'stop' => ['type' => 'BOOLEAN'], 'steps' => ['type' => 'ARRAY', 'items' => $step]], 'required' => ['key', 'title', 'when', 'priority', 'stop', 'steps']];
    return ['type' => 'OBJECT', 'properties' => ['understood' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'questions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']], 'changed' => ['type' => 'STRING'],
        'limits' => ['type' => 'OBJECT', 'properties' => ['max' => ['type' => 'INTEGER'], 'day_max' => ['type' => 'INTEGER'], 'gap_min' => ['type' => 'INTEGER'], 'quiet_from' => ['type' => 'STRING'], 'quiet_to' => ['type' => 'STRING'], 'why' => ['type' => 'STRING']], 'required' => ['max', 'day_max', 'gap_min', 'quiet_from', 'quiet_to', 'why']],
        'situations' => ['type' => 'ARRAY', 'items' => $sit]], 'required' => ['understood', 'questions', 'changed', 'limits', 'situations']];
}
/** Claude builds (or rebuilds) the whole follow-up plan from the owner's messages. Nothing is saved. */
function fuc_build(PDO $pdo, array $turns): array
{
    if (!function_exists('convo_ai')) throw new RuntimeException('The AI is not available.');
    $__brainScope = function_exists('brain_scope_cur') ? new BrainScope('customer', 'whatsapp', ['all_customer_facts' => true]) : null;   // the texts are customer messages
    $sys = "You are the follow-up planner of TM Perfume House (Indian online perfume brand, Surat) on WhatsApp. A customer chatted, we replied, and the customer went QUIET. "
        . "From the OWNER'S MESSAGES (a general summary, then corrections) build the COMPLETE follow-up plan: the situations (what the customer did before going quiet), and for each a timeline of steps.\n"
        . "RULES: after_min = minutes after OUR last message (5 = 5 minutes, 120 = 2 hours, 1440 = 1 day). Steps before 1410 minutes are normal WhatsApp messages: write them in 'text' (1–3 short lines, warm, Hinglish unless the selling rules say otherwise, one easy next step) and leave 'template' empty. "
        . "Steps at 1410 minutes or later can ONLY be a pre-made template that Meta approves: write it in 'template' (no greeting-only text, polite, may use {{1}} for the first name and NO other variable, no emojis at the start, 1–3 lines), set 'category' UTILITY if it is truly a reminder about their enquiry/order without any offer, else MARKETING, and put the same idea in 'text' with {name} instead of {{1}}. "
        . "Follow the owner's timings exactly when he gives them; where he gives none, choose sensible ones and say so in 'understood'. The owner's NEWEST message always wins over older ones: read ALL his messages together and rewrite the WHOLE plan from them — never ask which one wins. Ask a question ('Not sure') only when a wrong guess would put a wrong price or number in front of a customer, at most one; for every other detail choose sensibly and say what you chose in 'understood'. Timings given in days (2 days, 3 days) are steps of 1440 minutes or more, so they are templates. Make the templates of different situations different from each other. Usually 3–5 steps inside 24 hours and 1–2 templates after. Max 8 steps per situation, max 10 situations. "
        . "Always include a STOP situation (key angry, stop true, no steps) for a customer who is frustrated / says stop. Keys: short lowercase words; reuse pay (price agreed, no payment), ask (will ask family / think), quiet (asked price then quiet), first (stopped after the first reply), angry when they fit. priority 1 = closest to payment (wins when two fit). "
        . "Prices, offers, discounts, stock and policies ONLY from STORE FACTS and the owner's limits — never invent a number, never mention percentages; if unsure write no number. "
        . "limits: suggest the follow-up limits this plan needs (max follow-ups per quiet customer, max a day, the shortest gap in minutes, quiet hours like 10 pm – 9 am); the owner's words win. "
        . "understood: 4–8 short bullets in plain English saying what you understood and what you decided yourself. questions: 0–4 short questions only where the owner's intent is really unclear. changed: one line about what you changed since your last plan (empty the first time).";
    $txt = fuc_context($pdo) . "\nOWNER'S MESSAGES (oldest first):\n";
    foreach ($turns as $t) { if (($t['who'] ?? '') === 'owner') $txt .= '- ' . mb_substr((string)$t['text'], 0, 3000) . "\n"; }
    $last = null; foreach (array_reverse($turns) as $t) if (($t['who'] ?? '') === 'claude' && !empty($t['plan'])) { $last = $t['plan']; break; }
    if ($last) $txt .= "\nYOUR LAST PLAN (rebuild it with the owner's newest message; keep everything he did not ask to change):\n" . mb_substr(json_encode($last, JSON_UNESCAPED_UNICODE), 0, 12000) . "\n";
    [$ok, $v, $why] = convo_ai($sys, [['text' => $txt]], fuc_schema(), false, 7000, 'claude');
    if (!$ok || !is_array($v)) throw new RuntimeException((string)($why ?: 'Claude could not build the plan — try again.'));
    return fuc_clean_plan($pdo, $v);
}
/** Tidy what Claude returned: times, channels, lengths, no invented numbers (a text with a number nobody taught is cut back to the owner's idea). */
function fuc_clean_plan(PDO $pdo, array $v): array
{
    $allowed = []; if (function_exists('teach_facts_prompt')) { [, $allowed] = teach_facts_prompt($pdo, []); $allowed = (array)$allowed; } if (function_exists('brain_allowed_nums')) $allowed += brain_allowed_nums($pdo);
    $sits = []; $seen = [];
    foreach (array_slice((array)($v['situations'] ?? []), 0, 10) as $s) {
        if (!is_array($s)) continue; $key = substr(preg_replace('/[^a-z0-9]+/', '', strtolower((string)($s['key'] ?? ''))), 0, 16) ?: 's' . count($sits); if (isset($seen[$key])) continue; $seen[$key] = 1;
        $stop = !empty($s['stop']) || $key === 'angry'; $steps = [];
        if (!$stop) foreach (array_slice((array)($s['steps'] ?? []), 0, 8) as $x) {
            if (!is_array($x)) continue; $m = max(1, min(43200, (int)($x['after_min'] ?? 0))); $ch = fuc_ch($m / 60);
            $text = trim(mb_substr((string)($x['text'] ?? ''), 0, 700)); $tpl = trim(mb_substr(str_replace('{name}', '{{1}}', (string)($x['template'] ?? '')), 0, 900));
            if ($ch === 'tpl' && $tpl === '') $tpl = str_replace('{name}', '{{1}}', $text); if ($ch === 'tpl' && $text === '') $text = str_replace('{{1}}', '{name}', $tpl);
            if ($text === '') continue; $warn = [];
            if (function_exists('fu_text_ok') && !fu_text_ok($pdo, str_replace('{name}', '', $text), $allowed, $warn)) $warn = array_slice($warn, 0, 2); else $warn = [];
            $steps[] = ['after_min' => $m, 'ch' => $ch, 'text' => $text, 'template' => $ch === 'tpl' ? $tpl : '', 'category' => strtoupper((string)($x['category'] ?? '')) === 'UTILITY' ? 'UTILITY' : 'MARKETING', 'why' => trim(mb_substr((string)($x['why'] ?? ''), 0, 200)), 'warn' => $warn];
        }
        usort($steps, function ($a, $b) { return $a['after_min'] <=> $b['after_min']; });
        $sits[] = ['key' => $key, 'title' => trim(mb_substr((string)($s['title'] ?? 'Situation'), 0, 60)) ?: 'Situation', 'when' => trim(mb_substr((string)($s['when'] ?? ''), 0, 240)), 'priority' => max(0, min(20, (int)($s['priority'] ?? 5))), 'stop' => $stop, 'steps' => $steps];
    }
    $l = (array)($v['limits'] ?? []);
    $lim = ['max' => max(1, min(10, (int)($l['max'] ?? 4))), 'day_max' => max(1, min(10, (int)($l['day_max'] ?? 3))), 'gap_min' => max(1, min(1440, (int)($l['gap_min'] ?? 30))), 'quiet_from' => (string)($l['quiet_from'] ?? '10 pm'), 'quiet_to' => (string)($l['quiet_to'] ?? '9 am'), 'why' => trim(mb_substr((string)($l['why'] ?? ''), 0, 240))];
    return ['understood' => array_values(array_filter(array_map(function ($x) { return trim(mb_substr((string)$x, 0, 300)); }, (array)($v['understood'] ?? [])))), 'questions' => array_values(array_filter(array_map(function ($x) { return trim(mb_substr((string)$x, 0, 300)); }, (array)($v['questions'] ?? [])))),
        'changed' => trim(mb_substr((string)($v['changed'] ?? ''), 0, 300)), 'limits' => $lim, 'situations' => $sits];
}
/** Write a proposal into the engine. $keys = the situations to take (empty = all). The rest of the old plan is replaced (undo is in History). */
function fuc_apply(PDO $pdo, array $prop, array $keys = []): array
{
    $P = fu_plans_get($pdo); $old = []; foreach ($P['situations'] as $s) { $old[$s['key'] ?? preg_replace('/^f_/', '', $s['id'])] = $s; $old['t:' . mb_strtolower($s['title'])] = $s; }
    $known = ['pay', 'ask', 'quiet', 'first', 'angry']; $new = []; $now = fu_now();
    foreach ((array)$prop['situations'] as $s) {
        if ($keys && !in_array($s['key'], $keys, true)) { if (isset($old[$s['key']])) $new[] = $old[$s['key']]; continue; }
        $prev = $old[$s['key']] ?? ($old['t:' . mb_strtolower($s['title'])] ?? null);
        $id = in_array($s['key'], $known, true) ? 'f_' . $s['key'] : ($prev ? (string)$prev['id'] : fu_id());
        $steps = []; foreach ($s['steps'] as $i => $x) { $pv = $prev['steps'][$i] ?? null; $sameTpl = $pv && (string)($pv['tpl_body'] ?? '') === (string)$x['template'];   // a template already at Meta stays linked while its words are the same
            $steps[] = ['after_h' => round($x['after_min'] / 60, 4), 'text' => $x['text'], 'remark' => '', 'ch' => $x['ch'], 'tpl_body' => $x['template'], 'tpl_cat' => $x['category'], 'tpl_id' => $sameTpl ? (int)($pv['tpl_id'] ?? 0) : 0]; }
        $new[] = fu_sit_clean(['id' => $id, 'key' => $s['key'], 'title' => $s['title'], 'desc' => $s['when'], 'kind' => $s['stop'] ? 'stop' : 'nudge', 'stage' => 'saved', 'on' => true, 'priority' => $s['priority'], 'remark_all' => '', 'steps' => $steps, 'created_at' => $prev['created_at'] ?? $now, 'updated_at' => $now]);
    }
    if ($keys) foreach ($P['situations'] as $s) { $k = $s['key'] ?? preg_replace('/^f_/', '', $s['id']); $inProp = false; foreach ($prop['situations'] as $x) if ($x['key'] === $k) $inProp = true; if (!$inProp) $new[] = $s; }
    $snap = ['fuplans' => (string)($pdo->query("SELECT body FROM lib_facts WHERE k = 'fuplans'")->fetchColumn() ?: '')];
    fu_plans_put($pdo, ['situations' => $new]);
    $l = (array)($prop['limits'] ?? []); if ($l) fuc_limits_set($pdo, $l);
    if (function_exists('learn_hist_add')) learn_hist_add($pdo, 'Saved the follow-up plan from Claude (' . count($new) . ' situations)', $snap);
    return $new;
}
function fuc_limits_set(PDO $pdo, array $l): array
{
    $q = function_exists('fu_parse_quiet') ? fu_parse_quiet(trim((string)($l['quiet_from'] ?? '')) . ' ' . trim((string)($l['quiet_to'] ?? ''))) : null;
    $o = ['max' => max(1, min(10, (int)($l['max'] ?? 4))), 'day_max' => max(1, min(10, (int)($l['day_max'] ?? 3))), 'gap_min' => max(1, min(1440, (int)($l['gap_min'] ?? 30)))]; if ($q) $o['quiet'] = $q;
    setting_set($pdo, 'fu_plan_limits', json_encode($o)); return $o;
}
/** Send one step's template to Meta (through the ladder table, so it is also used as the ladder version for that time). Returns [ok, message]. */
function fuc_tpl_send(PDO $pdo, string $sid, int $n, int $me): array
{
    if (!function_exists('fu_ladder_submit') || !function_exists('fu_ladder_table') || !fu_ladder_table($pdo)) return [false, 'The template module is not available.'];
    $P = fu_plans_get($pdo); $i = fu_sit_find($P, $sid); if ($i === null) return [false, 'Situation not found.']; $s = $P['situations'][$i]; $x = $s['steps'][$n] ?? null; if (!$x) return [false, 'Step not found.'];
    if ((int)($x['tpl_id'] ?? 0) > 0) return [true, 'already sent']; $body = trim((string)($x['tpl_body'] ?? '')); if ($body === '') $body = str_replace('{name}', '{{1}}', (string)$x['text']); if ($body === '') return [false, 'The step has no text.'];
    $name = 'fu_' . substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($s['title'])), 0, 30) . '_' . ($n + 1);
    $d = ['name' => $name, 'language' => 'en', 'category' => (string)($x['tpl_cat'] ?? 'MARKETING'), 'body' => $body, 'variable_examples' => strpos($body, '{{1}}') !== false ? ['Rahul'] : [], 'why' => 'Follow-up · ' . $s['title'] . ' · step ' . ($n + 1)];
    $pdo->prepare("INSERT INTO wa_fu_ladder (rung, ver, tpl_id, draft, status, created_at) VALUES (?, 'S', 0, ?, 'draft', ?)")->execute([fu_rung((float)$x['after_h']), json_encode($d, JSON_UNESCAPED_UNICODE), fu_now()]);
    $lid = (int)$pdo->lastInsertId(); $row = $pdo->query("SELECT * FROM wa_fu_ladder WHERE id = $lid")->fetch(); [$ok, $e] = fu_ladder_submit($pdo, $row, $me);
    $tid = (int)$pdo->query("SELECT tpl_id FROM wa_fu_ladder WHERE id = $lid")->fetchColumn();
    if ($tid > 0) { $P['situations'][$i]['steps'][$n]['tpl_id'] = $tid; fu_plans_put($pdo, $P); }
    return [$ok && $tid > 0, $ok ? 'sent to Meta' : (string)$e];
}
/** The other things that also message a quiet customer — shown so the owner knows there is ONE engine. */
function fuc_others(PDO $pdo): array
{
    $o = []; try { $n = (int)$pdo->query("SELECT COUNT(*) FROM wa_fu_rules WHERE active = 1")->fetchColumn(); if ($n) $o[] = ['what' => 'Classic follow-up rules (old)', 'n' => $n, 'state' => fu_mode($pdo) !== 'off' ? 'paused while this plan is on' : 'running']; } catch (Throwable $e) { }
    try { $n = (int)$pdo->query("SELECT COUNT(*) FROM wa_flows WHERE active = 1 AND trig = 'no_reply'")->fetchColumn(); if ($n) $o[] = ['what' => 'Flows (old) with the “no reply” start', 'n' => $n, 'state' => in_array(fu_mode($pdo), ['auto24', 'autotpl'], true) ? 'paused while this plan sends by itself' : 'running']; } catch (Throwable $e) { }
    return $o;
}

}   // function_exists guard

$__fuc = (string)($action ?? ($_GET['action'] ?? ''));
if (strpos($__fuc, 'fu.coach_') === 0) {
    $auth = require_auth(); $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (!function_exists('fu_tables') || !fu_tables($pdo)) json_response(['error' => 'Follow-up storage is not available.'], 500);
    if (function_exists('wa_guard_admin')) wa_guard_admin($pdo, $auth);
    $me = (int)($auth['employee_id'] ?? 0); $in = $isPost ? json_input() : [];
    $view = function () use ($pdo) { $C = fuc_get($pdo); $L = fu_limits($pdo); $pl = fuc_plan_limits($pdo);
        return ['ok' => true, 'turns' => $C['turns'], 'proposal' => $C['proposal'], 'plan' => fuc_plan_view($pdo), 'mode' => fu_mode($pdo), 'others' => fuc_others($pdo),
            'limits' => ['max' => $L['max'], 'day_max' => $L['day_max'], 'gap_min' => $L['gap_min'], 'quiet_from' => fuc_hm($L['quiet'][0]), 'quiet_to' => fuc_hm($L['quiet'][1]), 'own' => !empty($pl)], 'ladder_ok' => function_exists('fu_ladder_submit')]; };
    if ($__fuc === 'fu.coach_get') json_response($view());
    if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
    switch ($__fuc) {
        case 'fu.coach_ask': {
            $t = trim(mb_substr((string)($in['text'] ?? ''), 0, 4000)); if ($t === '') json_response(['error' => 'Write what you want first.'], 422);
            $C = !empty($in['reset']) ? ['turns' => [], 'proposal' => null] : fuc_get($pdo); $C['turns'][] = ['who' => 'owner', 'text' => $t, 'at' => date('Y-m-d H:i:s')];
            ignore_user_abort(true); @set_time_limit(180);
            try { $p = fuc_build($pdo, $C['turns']); } catch (Throwable $e) { json_response(['error' => $e->getMessage()], 422); }
            $C['turns'][] = ['who' => 'claude', 'understood' => $p['understood'], 'questions' => $p['questions'], 'changed' => $p['changed'], 'plan' => ['situations' => $p['situations'], 'limits' => $p['limits']], 'at' => date('Y-m-d H:i:s')];
            $C['proposal'] = $p; fuc_put($pdo, $C); json_response($view());
        }
        case 'fu.coach_apply': {
            $C = fuc_get($pdo); if (empty($C['proposal'])) json_response(['error' => 'Ask Claude for a plan first.'], 422);
            $keys = array_values(array_filter(array_map('strval', (array)($in['keys'] ?? []))));
            $new = fuc_apply($pdo, $C['proposal'], $keys); $C['turns'][] = ['who' => 'system', 'text' => 'Saved ' . count($new) . ' situations as the live plan.', 'at' => date('Y-m-d H:i:s')]; $C['proposal'] = null; fuc_put($pdo, $C);
            json_response($view() + ['saved' => count($new)]);
        }
        case 'fu.coach_limits': { fuc_limits_set($pdo, $in); if (function_exists('learn_hist_add')) learn_hist_add($pdo, 'Changed the follow-up limits'); json_response($view()); }
        case 'fu.coach_tpl': {
            ignore_user_abort(true); @set_time_limit(150); $res = [];
            if (isset($in['sid'])) $res[] = fuc_tpl_send($pdo, (string)$in['sid'], (int)($in['n'] ?? 0), $me);
            else { foreach (fu_plans_get($pdo)['situations'] as $s) if ($s['stage'] === 'saved' && $s['kind'] === 'nudge') foreach ($s['steps'] as $i => $x) if (($x['ch'] ?? fuc_ch((float)$x['after_h'])) === 'tpl' && (int)($x['tpl_id'] ?? 0) === 0 && count($res) < 12) $res[] = fuc_tpl_send($pdo, (string)$s['id'], $i, $me); }
            $ok = count(array_filter($res, function ($r) { return $r[0]; })); $bad = array_values(array_filter(array_map(function ($r) { return $r[0] ? '' : $r[1]; }, $res)));
            json_response($view() + ['sent' => $ok, 'errors' => $bad]);
        }
        case 'fu.coach_clear': { $C = fuc_get($pdo); $C['turns'] = []; $C['proposal'] = null; fuc_put($pdo, $C); json_response($view()); }
    }
    json_response(['error' => 'Unknown action.'], 404);
}
