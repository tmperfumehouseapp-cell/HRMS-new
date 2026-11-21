<?php
$src = file_get_contents(__DIR__ . '/../empmanagment/wa_growth.php');
preg_match('/function wag_bot_unsafe\(string \$t\): bool\n\{.*?\n\}\n/s', $src, $m); eval($m[0]);
$bad = ["Aap 10ml free le sakte ho", "Free gift milega", "Payment received, order confirmed", "muft 10 ml tester", "Complimentary sample"];
$ok = ["Ye perfume 50 ml ka hai, 20 ml bhi milta hai", "Kaunsi fragrance pasand hai? Fresh ya sweet?", "Delivery 3-4 din me hoti hai", "Aap kitna budget rakhte ho?"];
foreach ($bad as $b) if (!wag_bot_unsafe($b)) { echo "FAIL should block: $b\n"; exit(1); }
foreach ($ok as $b) if (wag_bot_unsafe($b)) { echo "FAIL should allow: $b\n"; exit(1); }
echo "bot unsafe ok\n";
