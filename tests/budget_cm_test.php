<?php
// The commission engine belongs to Total Budgeting (empmanagment/budget_cm.php) and must load and answer WITHOUT any Boss Engine file.
function json_response($d, int $c = 200): void { throw new Exception(json_encode($d), $c); }
$GLOBALS['cm_funcs_only'] = true; require __DIR__ . '/../empmanagment/budget_cm.php'; $bad = 0;
foreach (['cm_chart', 'cm_pct', 'cm_offers', 'cm_offer_match', 'cm_code_need', 'cm_bonus', 'cm_day', 'cm_paid_day', 'cm_recount', 'cm_action', 'cm_now'] as $f) if (!function_exists($f)) { echo "missing $f\n"; $bad++; }
foreach (['bd_now', 'b2_day', 'b2_log', 'bd_log', 'bd_need_admin'] as $f) if (function_exists($f)) { echo "the Boss Engine was loaded ($f)\n"; $bad++; }
$src = file_get_contents(__DIR__ . '/../empmanagment/budget_cm.php'); if (preg_match('/\b(bd|b2)_[a-z0-9_]+\s*\(/', str_replace('INTO bd_log (', '', preg_replace('#/\*.*?\*/#s', '', $src)), $m)) { echo "budget_cm.php still calls the Boss Engine: {$m[0]}\n"; $bad++; }
if (cm_code_need(10, 70) !== 7 || cm_code_need(0, 70) !== 0) { echo "cm_code_need changed\n"; $bad++; }
foreach (['api.php', 'budget.php'] as $f) { $s = file_get_contents(__DIR__ . '/../empmanagment/' . $f); if (!preg_match('/budget_cm\.php/', $s)) { echo "$f does not load budget_cm.php\n"; $bad++; } }
if (preg_match("/function cm_engine_load.*?\\n}/s", file_get_contents(__DIR__ . '/../empmanagment/api.php'), $m) && strpos($m[0], 'boss') !== false && strpos($m[0], "require_once __DIR__ . '/boss") !== false) { echo "cm_engine_load still loads the Boss Engine\n"; $bad++; }
// the old action names are answered by the same code; only Admin / HR / the Commission tick may use them
try { cm_action(new PDO('sqlite::memory:'), 'cm_recount', ['role' => 'Employee', 'username' => 'x'], [], false); echo "no refusal\n"; $bad++; } catch (Exception $e) { if ($e->getCode() !== 403) { echo "staff were not refused (" . $e->getCode() . ")\n"; $bad++; } }
try { cm_action(new PDO('sqlite::memory:'), 'cm_nothing', ['role' => 'Admin'], [], false); } catch (Exception $e) { if ($e->getCode() !== 404) { echo "unknown action not 404\n"; $bad++; } }
echo $bad ? "budget_cm_test: $bad FAILED\n" : "budget_cm_test: ok\n"; exit($bad ? 1 : 0);
