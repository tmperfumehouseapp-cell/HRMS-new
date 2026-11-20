<?php
// A function declared inside wa_line.php must not exist anywhere else: PHP stops with "Cannot redeclare" and the WHOLE WhatsApp engine and every WhatsApp screen go down (21 Nov: wl_tables / wl_plan of wa_lists.php).
$root = dirname(__DIR__); $src = file_get_contents($root . '/empmanagment/wa_line.php'); preg_match_all('/function\s+(\w+)\s*\(/', $src, $m); $mine = array_unique($m[1]); $bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { $p = (string)$f; if (substr($p, -4) !== '.php' || strpos($p, '/.git/') !== false || strpos($p, 'empmanagment/wa_line.php') !== false || strpos($p, '/tests/') !== false) continue;
    $t = file_get_contents($p); foreach ($mine as $n) if (preg_match('/function\s+' . preg_quote($n, '/') . '\s*\(/', $t)) $bad[] = "$n also in " . substr($p, strlen($root) + 1); }
if ($bad) { echo "FAIL: " . implode("; ", $bad) . "\n"; exit(1); }
foreach ($mine as $n) if (strpos($n, 'wln_') !== 0) { echo "FAIL: $n does not use the wln_ prefix\n"; exit(1); }
echo "lineup names ok (" . count($mine) . " functions)\n";
