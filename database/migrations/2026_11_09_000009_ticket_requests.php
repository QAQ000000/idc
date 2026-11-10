<?php

use App\Core\Database;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        foreach (['request_key' => "CHAR(36) NULL COMMENT '作者作用域的提交UUID，历史行可空'", 'request_hash' => "CHAR(64) NULL COMMENT '提交动作与正文指纹'"] as $column => $definition) {
            $s = $db->query("SHOW COLUMNS FROM ticket_replies LIKE '$column'");
            if (! $s->fetch()) {
                $db->exec("ALTER TABLE ticket_replies ADD $column $definition");
            }
        }
        foreach (['uk_ticket_user_request' => 'user_id', 'uk_ticket_admin_request' => 'admin_id'] as $name => $actor) {
            $s = $db->query("SHOW INDEX FROM ticket_replies WHERE Key_name='$name'");
            if (! $s->fetch()) {
                $db->exec("ALTER TABLE ticket_replies ADD UNIQUE KEY $name ($actor,request_key)");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Accepted ticket request identities require a reviewed rollback');
    }
};
