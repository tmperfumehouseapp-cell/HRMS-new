<?php
// A function declared in two included files without a function_exists() guard kills the whole engine with "Cannot redeclare" (21 Nov 2026: wl_tables / wl_plan in wa_lists.php and wa_line.php stopped Module B, marketing and the template job for 20 minutes).
$dir = dirname(__DIR__) . '/empmanagment'; $decl = [];
/* only the files wa_more.php pulls in together (the WhatsApp module family) can clash with each other at run time */
$set = [$dir . '/wa_more.php']; preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*\'\/([A-Za-z0-9_]+\.php)\'/', file_get_contents($dir . '/wa_more.php'), $mm); foreach ($mm[1] as $n) $set[] = $dir . '/' . $n;
foreach (array_unique($set) as $f) {
    if (!is_file($f)) continue;
    $lines = file($f); $inCls = false;
    foreach ($lines as $i => $ln) {
        if (preg_match('/^(abstract\s+|final\s+)?(class|interface|trait)\s/', $ln)) $inCls = true;
        if ($inCls && preg_match('/^\}/', $ln)) { $inCls = false; continue; }
        if ($inCls) continue;
        if (!preg_match('/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $ln, $m)) continue;
        $name = $m[1]; $ctx = implode('', array_slice($lines, max(0, $i - 3), 4));
        $guarded = (bool)preg_match('/function_exists\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*\)/', $ctx);
        $decl[$name][] = [basename($f), $guarded];
    }
}
$bad = 0;
foreach ($decl as $name => $d) {
    if (count($d) < 2) continue;
    foreach ($d as [$file, $g]) if (!$g) { echo "FAIL: $name is declared in " . implode(', ', array_column($d, 0)) . " and is not guarded in $file\n"; $bad++; break; }
}
if ($bad) exit(1);
echo "no redeclare ok\n";
