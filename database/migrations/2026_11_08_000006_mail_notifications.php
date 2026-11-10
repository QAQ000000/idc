<?php

use App\Core\Database;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        $columns = array_column($db->query('SHOW COLUMNS FROM notification_deliveries')->fetchAll(), null, 'Field');
        if (! isset($columns['admin_id'])) {
            $db->exec("ALTER TABLE notification_deliveries ADD admin_id INT UNSIGNED NULL COMMENT '管理员收件人 ID (与 user_id 二选一，发送前重查)' AFTER user_id");
        }
        if (! isset($columns['context'])) {
            $db->exec("ALTER TABLE notification_deliveries ADD context JSON NULL COMMENT '事件身份快照 (kind、原任务令牌或实例到期日/提醒日，不含消息正文及密钥)' AFTER admin_id");
        }
        if ($columns['user_id']['Null'] !== 'YES') {
            $db->exec("ALTER TABLE notification_deliveries MODIFY user_id BIGINT UNSIGNED NULL COMMENT '客户收件人 ID (与 admin_id 二选一，发送前重查)'");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Notification audit history requires a reviewed rollback');
    }
};
