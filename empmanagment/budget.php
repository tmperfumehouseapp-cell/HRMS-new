<?php
/**
 * budget.php — Sales → Total Budgeting: the numbers YOU type (target, lump sum, fixed expenses).
 * Loaded by api.php only for  budget.*  actions (keep this file in the SAME folder as api.php).
 *
 *   budget.get   GET  month=YYYY-MM          → { month, target, lump, expenses:[{name, amount}] }
 *   budget.save  POST {month, target, lump, expenses:[{name, amount}]}   → saves them (Admin only)
 *
 * Fixed expenses (electricity, rent ...) are monthly amounts, kept for every month until you change them.
 * Target and lump sum are kept per month. Everything else on the Total Budgeting page (commission, leaders,
 * salary ÷ 30, penalties) is read from the pages that already exist, so nothing is typed twice.
 */
if (!function_exists('budget_cfg_from')) {
    function budget_cfg_from(array $rows): array
    {
        $exp = [];
        foreach ($rows as $x) {
            $n = trim((string)($x['name'] ?? '')); $a = $x['amount'] ?? 0;
            if ($n !== '' && is_numeric($a)) $exp[] = ['name' => mb_substr($n, 0, 60), 'amount' => round(max(0, min(100000000, (float)$a)), 2)];
        }
        return array_slice($exp, 0, 40);
    }
}
if (!function_exists('budget_cfg')) {
    function budget_cfg(PDO $pdo): array
    {
        $j = json_decode((string)setting_get($pdo, 'budget_cfg', ''), true);
        if (!is_array($j)) $j = [];
        return ['expenses' => budget_cfg_from((array)($j['expenses'] ?? [])), 'months' => is_array($j['months'] ?? null) ? $j['months'] : []];
    }
}

$__bg = (string)($action ?? '');
if ($__bg === 'budget.get') {
    $auth = require_auth();
    if (!user_can($auth, 'commission')) json_response(['error' => 'You do not have permission to do this.'], 403);
    $month = trim((string)($_GET['month'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_response(['error' => 'month must be YYYY-MM.'], 422);
    $c = budget_cfg($pdo); $m = $c['months'][$month] ?? [];
    json_response(['month' => $month, 'target' => (float)($m['target'] ?? 0), 'lump' => (float)($m['lump'] ?? 0), 'expenses' => $c['expenses'], 'can_edit' => convo_is_admin($auth)]);
}
if ($__bg === 'budget.save') {
    $auth = require_auth();
    if (!convo_is_admin($auth)) json_response(['error' => 'Only Admin can change the budget.'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['error' => 'Method not allowed.'], 405);
    $in = json_input();
    $month = trim((string)($in['month'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) json_response(['error' => 'month must be YYYY-MM.'], 422);
    $c = budget_cfg($pdo);
    foreach (['target', 'lump'] as $k) {
        $v = $in[$k] ?? 0;
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1000000000) json_response(['error' => 'Type ' . $k . ' as an amount in rupees.'], 422);
        $c['months'][$month][$k] = round((float)$v, 2);
    }
    if (isset($in['expenses']) && is_array($in['expenses'])) {
        $c['expenses'] = budget_cfg_from($in['expenses']);
    }
    setting_set($pdo, 'budget_cfg', json_encode($c));
    json_response(['ok' => true]);
}
json_response(['error' => 'Unknown action.'], 404);
