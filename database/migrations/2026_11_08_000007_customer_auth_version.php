<?php

use App\Core\Database;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        if (! $db->query("SHOW COLUMNS FROM users LIKE 'auth_version'")->fetch()) {
            $db->exec("ALTER TABLE users ADD auth_version BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户认证版本，改密递增以撤销旧登录和重置凭据' AFTER password");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Credential revocation history cannot be rolled back automatically');
    }
};
