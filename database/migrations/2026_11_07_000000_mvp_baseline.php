<?php

use App\Core\Database;
use App\Support\SchemaSql;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $business = array_values(array_diff($tables, ['migrations']));
        if ($business === []) {
            SchemaSql::import(database_path('schema.sql'));

            return;
        }
        // 仅接纳已完成历史 001/002 的原生 MVP，不隐式初始化/修复任意非空库。
        preg_match_all('/^CREATE TABLE IF NOT EXISTS `([^`]+)`/m', file_get_contents(database_path('schema.sql')), $matches);
        $expected = array_values(array_diff($matches[1], ['migrations']));
        sort($business);
        sort($expected);
        $columns = $db->query("SHOW COLUMNS FROM instances WHERE Field IN ('start_date', 'due_date')")->fetchAll();
        $indexes = $db->query("SHOW INDEX FROM instance_snapshots WHERE Key_name = 'uk_instance_type_remote_snapshot'")->fetchAll();
        $triggers = array_column($db->query('SHOW TRIGGERS')->fetchAll(), 'Trigger');
        $expectedTriggers = ['trg_transactions_check_balance', 'trg_transactions_no_update', 'trg_transactions_no_delete', 'trg_admin_audit_logs_no_update', 'trg_admin_audit_logs_no_delete'];
        sort($triggers);
        sort($expectedTriggers);
        if ($business !== $expected || count($columns) !== 2 || array_column($columns, 'Null') !== ['YES', 'YES']
            || count($indexes) !== 3 || array_column($indexes, 'Column_name') !== ['instance_id', 'type', 'remote_snapshot_id']
            || array_unique(array_column($indexes, 'Non_unique')) !== [0] || $triggers !== $expectedTriggers) {
            throw new RuntimeException('Existing schema is not the reviewed MVP baseline; back up and review historical migrations first');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Business baseline cannot be rolled back automatically; use a reviewed backup');
    }
};
