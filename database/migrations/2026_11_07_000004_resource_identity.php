<?php

use App\Core\Database;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        if ($db->query("SHOW INDEX FROM instances WHERE Key_name = 'uk_provider_remote'")->fetch()) {
            return;
        }
        if ($db->query('SELECT provider_id FROM instances WHERE remote_instance_id IS NOT NULL GROUP BY provider_id, remote_instance_id COLLATE utf8mb4_bin HAVING COUNT(*) > 1 LIMIT 1')->fetch()) {
            throw new RuntimeException('Duplicate remote resource bindings require manual ownership review');
        }
        $db->exec('ALTER TABLE instances MODIFY remote_instance_id VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL, DROP INDEX idx_provider_remote, ADD UNIQUE KEY uk_provider_remote (provider_id, remote_instance_id)');
    }

    public function down(): void
    {
        throw new RuntimeException('Resource ownership protection requires reviewed rollback');
    }
};
