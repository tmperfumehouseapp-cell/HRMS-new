<?php
// Owner 21 Nov 2026: Module B never sends to people who are in the line-up (queued / sending) of a running or scheduled campaign: the campaign (30-day etc.) goes first.
function ok($c, $m) { if (!$c) { echo "FAIL: $m\n"; exit(1); } }
$src = file_get_contents(dirname(__DIR__) . '/empmanagment/wa_rec_util.php');
ok(preg_match('/function wa_ru_lineup\(PDO \$pdo, array \$phones\): array\n\{.*?\n\}\n/s', $src, $m) === 1, 'helper present');
ok(substr_count($src, 'wa_ru_lineup($pdo, $phones)') === 2, 'main tick and warm tick both look up the line-up');
ok(strpos($src, "in the line-up of a campaign (the campaign goes first)") !== false, 'main tick skips them with a reason');
ok(strpos($src, "wa_ru_lineup(\$pdo, [(string)\$r['phone']])") !== false, 'retry tick skips them too');
if (extension_loaded('pdo_sqlite')) {
    $S = []; function setting_get($pdo, $k, $d = '') { global $S; return $S[$k] ?? $d; }
    eval($m[0]);
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE wa_campaigns (id INTEGER, status TEXT)"); $pdo->exec("CREATE TABLE wa_camp_items (id INTEGER, camp_id INTEGER, phone TEXT, status TEXT)");
    $pdo->exec("INSERT INTO wa_campaigns VALUES (1,'running'),(2,'scheduled'),(3,'done'),(4,'paused')");
    $pdo->exec("INSERT INTO wa_camp_items VALUES (1,1,'911','queued'),(2,2,'912','queued'),(3,1,'913','sending'),(4,1,'914','sent'),(5,3,'915','queued'),(6,4,'916','queued'),(7,1,'917','failed')");
    $r = wa_ru_lineup($pdo, ['911', '912', '913', '914', '915', '916', '917', '918']);
    ok(isset($r['911']) && isset($r['912']) && isset($r['913']), 'queued / sending in a running or scheduled campaign are left to the campaign');
    ok(!isset($r['914']) && !isset($r['915']) && !isset($r['916']) && !isset($r['917']) && !isset($r['918']), 'sent, done/paused campaign, failed and unknown people stay open for Module B');
    $S['wa_ru_lineup_skip'] = '0'; ok(wa_ru_lineup($pdo, ['911']) === [], 'switch-off setting works');
}
echo "ru lineup ok\n";
