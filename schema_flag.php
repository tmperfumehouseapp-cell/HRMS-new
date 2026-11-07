<?php
/* schema_flag.php — "this table set-up already ran" memory for the POS, kept in the server's temp folder (the HRMS api.php does the same).
   Without it every POS page asked the database's slow catalogue (information_schema) several times per request.
   The flag is tied to the database name and to the includes/*.php files: uploading a changed file (or moving to another database) runs the set-up once more. */
function pos_schema_sig(): string
{
    static $sig = null;
    if ($sig !== null) return $sig;
    $p = [defined('DB_NAME') ? DB_NAME : ''];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $f) $p[] = basename($f) . ':' . (int)@filemtime($f) . ':' . (int)@filesize($f);
    return $sig = md5(implode('|', $p));
}
function pos_schema_file(string $key): string { return sys_get_temp_dir() . '/tmpos_schema_' . preg_replace('/[^a-z0-9_]/i', '', $key); }
function pos_schema_seen(string $key): bool { $f = pos_schema_file($key); return is_file($f) && @file_get_contents($f) === pos_schema_sig(); }
function pos_schema_mark(string $key): void { @file_put_contents(pos_schema_file($key), pos_schema_sig()); }
