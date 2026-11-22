<?php
// Owner 23 Nov 2026: Module A may rest at night (03:00-09:00), Module B's utility follow-up never rests.
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$ru = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php'); $camp = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_camp.php');
ok(strpos($ru, "\$GLOBALS['wa_ru_noquiet'] = ((string)setting_get(\$pdo, 'wa_ru_quiet', '0') !== '1');") !== false, 'tick sets the flag unless wa_ru_quiet = 1');
ok(strpos($ru, 'finally { $GLOBALS[\'wa_ru_noquiet\'] = false; }') !== false, 'the flag is always cleared again (other modules keep their rest)');
ok(strpos($ru, "if (!wa_ru_in_window(\$pdo, \$nowTs)) { \$res['note'] = 'quiet hours'; return \$res; }") !== false, 'the tick uses its own window check');
ok(strpos($camp, "wa_quiet_hours() && empty(\$GLOBALS['wa_ru_noquiet'])") !== false, 'the template sender lets Module B through at night');
ok(substr_count($camp, "empty(\$GLOBALS['wa_ru_noquiet'])") === 1, 'only one place is opened');
ok(strpos(file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec.php'), "if (!wa_camp_in_window(\$pdo, \$nowTs)) { \$res['note'] = 'quiet hours'; return \$res; }") !== false, 'Module A (recovery) keeps quiet hours');
if (preg_match('/function wa_ru_in_window\(PDO \$pdo, int \$ts\): bool \{.*?\}\n/s', $ru, $m)) {
    function wa_camp_window($pdo) { return [0, 24]; } function wa_quiet_hours($ts = null) { $h = (int)date('G', (int)$ts); return $h >= 3 && $h < 9; }
    $pdo0 = (new ReflectionClass("PDO"))->newInstanceWithoutConstructor(); eval($m[0]); $night = mktime(4, 0, 0, 11, 23, 2026); $day = mktime(12, 0, 0, 11, 23, 2026);
    $GLOBALS['wa_ru_noquiet'] = false; ok(!wa_ru_in_window($pdo0, $night), 'at 04:00 with the flag off: rest'); ok(wa_ru_in_window($pdo0, $day), 'noon: works');
    $GLOBALS['wa_ru_noquiet'] = true; ok(wa_ru_in_window($pdo0, $night), 'at 04:00 with the flag on: Module B works');
} else ok(false, 'window helper present');
ok(substr_count($ru, "'wa_ru_camp_days', '8'") === 2, "Module B and the new numbers look at campaigns up to 8 days old");
echo "ru noquiet ok\n";
