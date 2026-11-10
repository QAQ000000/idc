<?php

use App\Core\Database;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        $s = $db->query("SHOW COLUMNS FROM tasks LIKE 'action'")->fetch();
        if (! str_contains($s['Type'], "'reinstall'")) {
            $db->exec("ALTER TABLE tasks MODIFY action ENUM('create','renew','upgrade','suspend','unsuspend','terminate','snapshot_create','snapshot_delete','backup_create','backup_delete','power','reinstall') NOT NULL");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Reinstall history requires a reviewed rollback');
    }
};
