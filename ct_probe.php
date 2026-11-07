<?php
// Temporary check file — upload next to api.php, open it in the browser, then DELETE it.
header('Content-Type: text/plain; charset=utf-8');
$dir = __DIR__;
echo "Folder this probe runs from:\n  $dir\n\n";
$f = $dir . '/api.php';
if (!is_file($f)) { echo "api.php is NOT in this folder.\n"; exit; }
$src = (string)file_get_contents($f);
echo "api.php in this folder:\n";
echo '  size       : ' . filesize($f) . " bytes (new file = about 1,807,397 or 1,836,090)\n";
echo '  modified   : ' . date('Y-m-d H:i:s', filemtime($f)) . " (server time)\n";
foreach (['ct.tower', 'theme.get', 'md.report', 'ct_run'] as $k) {
    echo '  contains ' . str_pad($k, 10) . ': ' . (strpos($src, $k) !== false ? 'YES' : 'NO  <-- old file') . "\n";
}
echo "\nOpcache: ";
if (function_exists('opcache_invalidate')) {
    $a = @opcache_invalidate($f, true);
    $b = function_exists('opcache_reset') ? @opcache_reset() : false;
    echo 'api.php cache cleared (' . ($a ? 'ok' : 'n/a') . '), full reset (' . ($b ? 'ok' : 'n/a') . ")\n";
} else echo "not enabled\n";
echo "\nNow open api.php?action=theme.get again. If this page says YES for all four and it still says Unknown action, tell Claude.\n";
echo "DELETE ct_probe.php from the server afterwards.\n";