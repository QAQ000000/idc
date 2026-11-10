<?php

use App\Core\Database;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        // 空库已由完整 schema 初始化；旧 MVP 增量添加可空字段，不改旧订单。
        $columns = array_column($db->query('SHOW COLUMNS FROM invoices')->fetchAll(), 'Field');
        if (! in_array('checkout_key', $columns, true)) {
            $db->exec("ALTER TABLE invoices ADD checkout_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '用户范围下单请求键，旧客户端可空', ADD checkout_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT '规范请求的 HMAC 摘要，不存密码明文', ADD UNIQUE KEY uk_user_checkout (user_id, checkout_key)");
        } elseif (! in_array('checkout_hash', $columns, true) || count($db->query("SHOW INDEX FROM invoices WHERE Key_name = 'uk_user_checkout'")->fetchAll()) !== 2) {
            throw new RuntimeException('Partial checkout migration requires review');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Removing checkout keys would permit duplicate purchases; restore only with a reviewed plan');
    }
};
