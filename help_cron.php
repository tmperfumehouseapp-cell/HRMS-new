<?php
// help_cron.php — run by the hPanel cron job every minute. Do not open this in a browser.
// Command for hPanel → Advanced → Cron Jobs (PHP):  php /home/YOUR_USER/domains/fourpirates.space/public_html/empmanagment/help_cron.php
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/help_cfg.php';
    $k = (string)($_SERVER['HTTP_X_HELP_KEY'] ?? '');
    if (!hash_equals(HELP_KEY, $k)) { http_response_code(403); header('Content-Type: text/plain'); exit("Not allowed\n"); }
    header('Content-Type: application/json');
}
@set_time_limit(150);
@ignore_user_abort(true);
require_once __DIR__ . '/help_lib.php';
try {
    $force = (PHP_SAPI === 'cli') ? in_array('force', $argv ?? [], true) : !empty($_GET['force']);
    $out = hlp_run($force);
} catch (Throwable $e) {
    $out = ['error' => $e->getMessage()];
}
echo json_encode($out), "\n";
