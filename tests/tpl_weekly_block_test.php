<?php
// Owner 21 Nov 2026: once Meta answers with the weekly limit for new utility templates, that account gets no new utility template for 6 hours (no hopeless calls).
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_mix.php');
ok(strpos($src, "wa_tpl_wk_block_") !== false, 'block setting is used');
ok(strpos($src, "weekly limit for creating utility") !== false, 'the Meta answer is recognised');
ok(strpos($src, "(int)setting_get(\$pdo, \$blk, '0') > time()") !== false, 'blocked accounts are skipped before any call');
ok(strpos($src, "time() + 6 * 3600") !== false, 'the block lasts 6 hours');
ok(strpos($src, "\$isU = strtoupper((string)(\$tpl['category'] ?? '')) === 'UTILITY'") !== false, 'only utility templates are blocked');
// behaviour with stubs
$S = []; function setting_get($p, $k, $d = '') { global $S; return $S[$k] ?? $d; } function setting_set($p, $k, $v) { global $S; $S[$k] = $v; }
preg_match('/function wa_tpl_try_create\(.*?\n\}\n/s', $src, $m); ok(!empty($m[0]), 'function found');
function wa_tpl_budget($p, $w) { return ['ok' => true, 'why' => '']; } function wa_mix_extra($t) { return []; } function wa_mix_extra_set($p, $i, $x) { }
$calls = 0; function wa_tpl_create_at($p, $t, $w) { global $calls; $calls++; return [false, 'Your business has reached the weekly limit for creating utility templates. This limit resets on a rolling 7-day window.']; }
eval($m[0]);
$pdo = (extension_loaded("pdo_sqlite") ? new PDO("sqlite::memory:") : null); $b = ['left' => 10]; $u = ['id' => 1, 'category' => 'UTILITY']; $mk = ['id' => 2, 'category' => 'MARKETING'];
wa_tpl_try_create($pdo, $u, '111', $b); ok($calls === 1, 'first utility call goes to Meta');
$r = wa_tpl_try_create($pdo, $u, '111', $b); ok($calls === 1 && $r[0] === false, 'second utility call for the same account is not sent');
wa_tpl_try_create($pdo, $u, '222', $b); ok($calls === 2, 'another account is still tried');
wa_tpl_try_create($pdo, $mk, '111', $b); ok($calls === 3, 'marketing templates are never blocked');
echo "tpl weekly block ok\n";
