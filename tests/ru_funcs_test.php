<?php
// Every wa_ru_* / wa_util_* function that the code calls must be defined somewhere in empmanagment/ (a slice edit once deleted two of them and the pop-up silently lost its "suitable" lines).
$dir = dirname(__DIR__) . '/empmanagment'; $src = ''; foreach (glob($dir . '/*.php') as $f) $src .= file_get_contents($f) . "\n";
preg_match_all('/\b(wa_ru_[a-z_]+|wa_util_[a-z_]+)\(/', $src, $m); $called = array_unique($m[1]); $bad = [];
foreach ($called as $fn) if (!preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\(/', $src)) $bad[] = $fn;
if ($bad) { echo "UNDEFINED: " . implode(', ', $bad) . "\n"; exit(1); }
echo "ok: " . count($called) . " functions defined\n";
