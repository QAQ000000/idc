<?php

use App\Core\Database;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $db = Database::getInstance();
        $s = $db->query("SHOW COLUMNS FROM product_categories LIKE 'parent_id'");
        if (! $s->fetch()) {
            $db->exec("ALTER TABLE product_categories ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL COMMENT '父级分类/分组ID，NULL表示一级分组' AFTER id");
        }
        $s = $db->query("SHOW INDEX FROM product_categories WHERE Key_name='idx_parent_id'");
        if (! $s->fetch()) {
            $db->exec('ALTER TABLE product_categories ADD INDEX idx_parent_id (parent_id)');
        }
        $fk = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_categories' AND CONSTRAINT_NAME = 'fk_product_categories_parent'")->fetchColumn();
        if (! $fk) {
            $db->exec('ALTER TABLE product_categories ADD CONSTRAINT fk_product_categories_parent FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE RESTRICT');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Category hierarchy migration cannot be rolled back automatically; use a reviewed backup');
    }
};
