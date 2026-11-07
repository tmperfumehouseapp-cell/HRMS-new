<?php
// includes/pos_pdo.php — runs the POS inside the HRMS database.
// Every POS table lives there as pos_<name> (pos_users, pos_orders, ...), so nothing collides with the HRMS tables
// (the HRMS has its own users / orders / fl_drops / fl_assign). The POS code keeps writing the plain names;
// this PDO subclass adds the prefix at the moment a query runs.
class PosPDO extends PDO {
    const PREFIX = 'pos_';
    const TABLES = [
        'stock_request_items', 'pending_order_items', 'stock_audit_lines', 'expense_categories', 'expense_accounts',
        'stock_receipts', 'stock_requests', 'stock_audits', 'stock_units', 'payment_modes', 'ledger_entries',
        'pending_orders', 'inventory_log', 'memo_items', 'order_items', 'categories', 'cash_notes', 'customers',
        'inventory', 'fl_assign', 'fl_drops', 'products', 'settings', 'expenses', 'billers', 'returns', 'orders',
        'memos', 'users',
    ];

    public static function rewrite($sql) {
        if (!is_string($sql) || $sql === '') return $sql;
        static $alt = null;
        if ($alt === null) $alt = implode('|', self::TABLES);
        $p = self::PREFIX;
        // "ON DUPLICATE KEY UPDATE col" is not a table reference
        $sql = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', "\x01ODKU\x01", $sql);
        // FROM x / JOIN x / INTO x / UPDATE x / TABLE x / EXISTS x / DESCRIBE x / TRUNCATE x / REFERENCES x
        $sql = preg_replace('/\b(FROM|JOIN|INTO|UPDATE|TABLE|EXISTS|DESCRIBE|TRUNCATE|REFERENCES)(\s+)(`?)(' . $alt . ')(?![A-Za-z0-9_])(`?)/i', '$1$2$3' . $p . '$4$5', $sql);
        // unaliased  table.column  references
        $sql = preg_replace('/(?<![\w.`\'"$>@:-])(' . $alt . ')\.(?=[A-Za-z_*`])/i', $p . '$1.', $sql);
        // information_schema ... TABLE_NAME = 'x'   /  TABLE_NAME IN ('x','y')   /  SHOW TABLES LIKE 'x'
        $sql = preg_replace('/(TABLE_NAME\s*(?:=|<>)\s*[\'"])(' . $alt . ')([\'"])/i', '$1' . $p . '$2$3', $sql);
        $sql = preg_replace_callback('/TABLE_NAME\s+IN\s*\(([^)]*)\)/i', function ($m) use ($alt, $p) {
            return preg_replace('/([\'"])(' . $alt . ')([\'"])/i', '$1' . $p . '$2$3', $m[0]);
        }, $sql);
        $sql = preg_replace('/(SHOW\s+TABLES\s+LIKE\s*[\'"])(' . $alt . ')([\'"])/i', '$1' . $p . '$2$3', $sql);
        return str_replace("\x01ODKU\x01", 'ON DUPLICATE KEY UPDATE', $sql);
    }

    /* a failed query says WHICH query (the database message alone does not) */
    private static function tag(Throwable $e, string $q): Throwable {
        try { $r = new ReflectionProperty('Exception', 'message'); $r->setAccessible(true); $r->setValue($e, $e->getMessage() . ' || SQL: ' . preg_replace('/\s+/', ' ', substr($q, 0, 260))); } catch (Throwable $x) {}
        return $e;
    }
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) { $q = self::rewrite($query); try { return parent::prepare($q, $options); } catch (PDOException $e) { throw self::tag($e, $q); } }
    #[\ReturnTypeWillChange]
    public function query($query, $fetchMode = null, ...$args) {
        $q = self::rewrite($query);
        try { return $fetchMode === null ? parent::query($q) : parent::query($q, $fetchMode, ...$args); } catch (PDOException $e) { throw self::tag($e, $q); }
    }
    #[\ReturnTypeWillChange]
    public function exec($statement) { $q = self::rewrite($statement); try { return parent::exec($q); } catch (PDOException $e) { throw self::tag($e, $q); } }
}
