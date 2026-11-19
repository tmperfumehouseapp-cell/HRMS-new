<?php
/**
 * finance_new.php - "Finance New": a Finance built from scratch, kept completely apart from the original Finance (finance_in.php, tables fi_*).   (actions "fn.*", loaded by api.php)
 *
 * Rules for this file
 *   - Own tables start with fn_ . Never read or write the fi_* tables from here unless a task says so; the original Finance keeps working as it is.
 *   - Permission: the "Finance" tick of the role (api.php action_permission: 'fn.' => 'finance').
 *   - Add each new action as a  case 'fn.something': { ... }  below. PHP 7.4 safe.
 */
if (!isset($pdo, $action) || !function_exists('json_response') || !function_exists('require_auth')) { http_response_code(403); exit; }
if (strpos((string)$action, 'fn.') !== 0) { return; }

$auth = require_auth();

switch ($action) {
    // a check that the new section is wired up (page -> api -> this file)
    case 'fn.ping': {
        json_response(['ok' => true, 'section' => 'Finance New', 'who' => (string)($auth['username'] ?? $auth['name'] ?? ''), 'time' => date('Y-m-d H:i:s')]);
        break;
    }
    default: json_response(['error' => 'Unknown Finance New action.'], 404);
}
