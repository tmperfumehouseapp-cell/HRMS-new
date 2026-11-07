<?php
/**
 * diag.php — front-end deployment check. Upload next to dashboard.html and
 * open https://tmbodywash.com/app/diag.php  (no login needed; read-only).
 * Shows exactly what is deployed so "why isn't X showing" is answered in
 * one screenshot. Delete it when done if you like.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$root = __DIR__;
$line = str_repeat('-', 60) . "\n";

echo "FRONT-END FOLDER: $root\n$line";

// 1. layout.js on the server
$lj = @file_get_contents("$root/assets/js/layout.js");
if ($lj === false) echo "assets/js/layout.js : MISSING\n";
else {
    preg_match("/LAYOUT_BUILD = '([^']+)'/", $lj, $m);
    echo "assets/js/layout.js : " . strlen($lj) . " bytes, build " . ($m[1] ?? 'OLD (no build marker)') . "\n";
    echo "   has Order Allocation link : " . (strpos($lj, 'order_allocation.html') !== false ? 'YES' : 'NO') . "\n";
    echo "   has live role sync        : " . (strpos($lj, 'syncRole') !== false ? 'YES' : 'NO') . "\n";
}
echo "order_allocation.html : " . (is_file("$root/order_allocation.html") ? 'present' : 'MISSING') . "\n";
echo ".htaccess             : " . (is_file("$root/.htaccess") ? 'present' : 'MISSING') . "\n";
echo "bump.php              : " . (is_file("$root/bump.php") ? 'present' : 'not uploaded') . "\n";

// 2. which layout.js URL every page loads (cache-buster stamp)
echo "\nSCRIPT VERSION STAMPS PER PAGE (information only — with the no-store headers below, stamps no longer matter)\n$line";
$stamps = [];
foreach (glob("$root/*.html") as $f) {
    $h = file_get_contents($f);
    preg_match('~assets/js/layout\.js(\?[^"\']*)?~', $h, $m);
    $stamps[basename($f)] = isset($m[0]) ? ($m[1] ?? '(no version)') : '(no layout.js)';
}
$groups = [];
foreach ($stamps as $f => $v) $groups[$v][] = $f;
foreach ($groups as $v => $files) echo str_pad($v, 22) . count($files) . " page(s): " . implode(', ', $files) . "\n";
if (count($groups) > 1) echo "=> Pages carry different stamps — harmless (no-store headers make every load fresh).\n";

// 3. cache headers the server really sends for layout.js
echo "\nSERVICE WORKER (sw.js)\n$line";
$sw = is_file("$root/sw.js") ? (string)file_get_contents("$root/sw.js") : '';
if ($sw === '') echo "sw.js : not on server (good — nothing can cache old pages)\n";
elseif (strpos($sw, 'SELF-REMOVING') !== false) echo "sw.js : self-removing version (good — browsers drop the old worker on next visit)\n";
elseif (strpos($sw, 'ALWAYS-FRESH') !== false) { preg_match('/\(v([\d.]+)\)/', $sw, $v); echo "sw.js : always-fresh worker " . ($v[1] ?? '') . " (good — stores nothing, forces every page/script from the server, shows phone reminders)\n"; }
elseif (strpos($sw, 'phone notifications only') !== false && strpos($sw, "addEventListener('fetch'") === false) echo "sw.js : push-only worker (ok — but upload the always-fresh one so browsers stop using their own cached copies)\n";
elseif (preg_match('/hrms-[\d.]+|hrms-shell-v\d+/', $sw, $m)) echo "sw.js : OLD worker {$m[0]} — upload the self-removing sw.js\n";
else echo "sw.js : unknown version — upload the self-removing sw.js\n";
echo "\nCACHE HEADERS SERVED FOR layout.js\n$line";
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$hdr = @get_headers("$base/assets/js/layout.js?diag=" . time());
if ($hdr) {
    foreach ($hdr as $h) if (preg_match('/^(HTTP|Cache-Control|Expires|Pragma|X-LiteSpeed|CF-Cache|Age):/i', $h)) echo "$h\n";
    $cc = implode(' ', $hdr);
    echo stripos($cc, 'no-store') !== false ? "=> OK: browsers will not keep old copies.\n" : "=> NOT OK: .htaccess no-cache rule is not active (missing file, or LiteSpeed/Cloudflare cache in front).\n";
} else echo "(could not fetch $base/assets/js/layout.js from the server itself)\n";

// 4. API reachability + build
echo "\nAPI\n$line";
$cfg = @file_get_contents("$root/assets/js/config.js");
preg_match('~API_BASE_URL\s*=\s*[\'"]([^\'"]+)[\'"]~', (string)$cfg, $m);
$api = $m[1] ?? '';
echo "config.js API_BASE_URL : " . ($api ?: 'NOT FOUND') . "\n";
if ($api) {
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
    $res = @file_get_contents($api . '?action=ping', false, $ctx);
    $j = $res ? json_decode($res, true) : null;
    if ($j) {
        echo "api.php build          : " . ($j['build'] ?? '?') . "\n";
        echo "modules in api.php     : " . implode(', ', $j['modules'] ?? []) . "\n";
        echo "has allocation module  : " . (in_array('allocation', $j['modules'] ?? [], true) ? 'YES' : 'NO -> api.php not updated') . "\n";
    } else echo "api.php ping failed    : " . substr((string)$res, 0, 200) . "\n";
}

echo "\nNEXT: log in, then open  {$api}?action=diag.access&token=<your token>  (the sidebar's build line must read the same layout/api builds as above).\n";