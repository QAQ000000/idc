<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use PDO;

final class CategoryService
{
    private const ALLOWED_TYPES = ['cloud', 'dedicated', 'cdn', 'hosting', 'software'];

    public function publicTree(): array
    {
        $db = Database::getInstance();
        $groups = $db->query('SELECT id, name, slug, type, headline, description, order_num FROM product_categories WHERE parent_id IS NULL AND status = 1 ORDER BY order_num DESC, id ASC')->fetchAll();
        $children = $db->query('SELECT id, parent_id, name, slug, type, headline, description, order_num FROM product_categories WHERE parent_id IS NOT NULL AND status = 1 ORDER BY order_num DESC, id ASC')->fetchAll();

        $grouped = [];
        foreach ($children as $child) {
            $grouped[(int) $child['parent_id']][] = [
                'id' => (int) $child['id'],
                'parent_id' => (int) $child['parent_id'],
                'name' => $child['name'],
                'slug' => $child['slug'],
                'type' => $child['type'],
                'headline' => $child['headline'] ?? '',
                'description' => $child['description'] ?? '',
                'order_num' => (int) $child['order_num'],
            ];
        }

        return array_map(static function (array $g) use ($grouped): array {
            $gid = (int) $g['id'];

            return [
                'id' => $gid,
                'name' => $g['name'],
                'slug' => $g['slug'],
                'type' => $g['type'],
                'headline' => $g['headline'] ?? '',
                'description' => $g['description'] ?? '',
                'order_num' => (int) $g['order_num'],
                'children' => $grouped[$gid] ?? [],
            ];
        }, $groups);
    }

    public function adminList(?int $admin = null): array
    {
        if ($admin !== null) {
            $this->authorizeRead($admin);
        }

        $db = Database::getInstance();
        $rows = $db->query('SELECT c.*,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products_count,
            (SELECT COUNT(*) FROM product_categories sub WHERE sub.parent_id = c.id) AS subcategories_count
            FROM product_categories c
            ORDER BY c.order_num DESC, c.id ASC')->fetchAll();

        $items = [];
        $childrenMap = [];
        foreach ($rows as $row) {
            $item = $this->project($row);
            $items[] = $item;
            if ($item['parent_id'] !== null) {
                $childrenMap[$item['parent_id']][] = $item;
            }
        }

        $tree = [];
        foreach ($items as $item) {
            if ($item['parent_id'] === null) {
                $item['children'] = $childrenMap[$item['id']] ?? [];
                $tree[] = $item;
            }
        }

        return ['items' => $items, 'tree' => $tree];
    }

    public function detail(int $id, ?int $admin = null): array
    {
        if ($admin !== null) {
            $this->authorizeRead($admin);
        }

        $row = $this->read($id);

        return $this->project($row);
    }

    public function create(int $admin, array $input): array
    {
        $this->authorizeSuper($admin);

        $name = trim(Request::text($input, 'name', 1, 100));
        $slug = trim(Request::text($input, 'slug', 1, 100));
        if (! preg_match('/^[a-z0-9_\-]+$/i', $slug)) {
            throw new ApiException('Slug can only contain letters, numbers, hyphens, and underscores', 422, 422);
        }

        $type = isset($input['type']) && is_string($input['type']) ? Request::text($input, 'type', 1, 20) : 'cloud';
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new ApiException('Invalid category type', 422, 422);
        }

        $orderNum = isset($input['order_num']) ? (int) $input['order_num'] : 0;
        $status = isset($input['status']) && (int) $input['status'] === 0 ? 0 : 1;
        $headline = isset($input['headline']) && is_string($input['headline']) ? trim($input['headline']) : null;
        $description = isset($input['description']) && is_string($input['description']) ? trim($input['description']) : null;
        $seoTitle = isset($input['seo_title']) && is_string($input['seo_title']) ? trim($input['seo_title']) : null;
        $seoKeywords = isset($input['seo_keywords']) && is_string($input['seo_keywords']) ? trim($input['seo_keywords']) : null;
        $seoDescription = isset($input['seo_description']) && is_string($input['seo_description']) ? trim($input['seo_description']) : null;

        $parentId = null;
        if (isset($input['parent_id']) && $input['parent_id'] !== null && $input['parent_id'] !== '') {
            $parentId = Request::id($input['parent_id']);
        }

        $db = Database::getInstance();
        $s = $db->prepare('SELECT id FROM product_categories WHERE slug=?');
        $s->execute([$slug]);
        if ($s->fetchColumn()) {
            throw new ApiException('Category slug already exists', 409, 409);
        }

        return Database::transaction(function (PDO $db) use ($admin, $parentId, $name, $slug, $type, $headline, $description, $seoTitle, $seoKeywords, $seoDescription, $orderNum, $status): array {
            if ($parentId !== null) {
                $s = $db->prepare('SELECT id, parent_id FROM product_categories WHERE id=? FOR UPDATE');
                $s->execute([$parentId]);
                $parent = $s->fetch();
                if (! $parent) {
                    throw new ApiException('Parent category not found', 404, 404);
                }
                if ($parent['parent_id'] !== null) {
                    throw new ApiException('Tree depth cannot exceed 2 levels; parent must be a level 1 group', 422, 422);
                }
            }

            $this->authorizeSuper($admin, $db);

            $stmt = $db->prepare('INSERT INTO product_categories (parent_id, name, slug, type, headline, description, seo_title, seo_keywords, seo_description, order_num, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$parentId, $name, $slug, $type, $headline, $description, $seoTitle, $seoKeywords, $seoDescription, $orderNum, $status]);
            $id = (int) $db->lastInsertId();

            $this->audit($db, $admin, $id, 'category_create', [
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $slug,
                'type' => $type,
                'order_num' => $orderNum,
                'status' => $status,
            ]);

            $row = $this->read($id, $db);

            return $this->project($row);
        });
    }

    public function update(int $admin, int $id, array $input): array
    {
        $this->authorizeSuper($admin);
        $old = $this->read($id);
        $this->checkVersion($old, $input);

        foreach (array_keys($input) as $key) {
            if (! in_array($key, ['name', 'slug', 'parent_id', 'type', 'headline', 'description', 'seo_title', 'seo_keywords', 'seo_description', 'order_num', 'status', 'expected_version'], true)) {
                throw new ApiException('Unsupported category edit field: '.$key);
            }
        }

        $name = isset($input['name']) ? trim(Request::text($input, 'name', 1, 100)) : $old['name'];
        $slug = isset($input['slug']) ? trim(Request::text($input, 'slug', 1, 100)) : $old['slug'];
        if (! preg_match('/^[a-z0-9_\-]+$/i', $slug)) {
            throw new ApiException('Slug can only contain letters, numbers, hyphens, and underscores', 422, 422);
        }

        $type = isset($input['type']) ? Request::text($input, 'type', 1, 20) : $old['type'];
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new ApiException('Invalid category type', 422, 422);
        }

        $orderNum = isset($input['order_num']) ? (int) $input['order_num'] : (int) $old['order_num'];
        $status = isset($input['status']) ? ((int) $input['status'] === 0 ? 0 : 1) : (int) $old['status'];
        $headline = array_key_exists('headline', $input) ? (is_string($input['headline']) ? trim($input['headline']) : null) : $old['headline'];
        $description = array_key_exists('description', $input) ? (is_string($input['description']) ? trim($input['description']) : null) : $old['description'];
        $seoTitle = array_key_exists('seo_title', $input) ? (is_string($input['seo_title']) ? trim($input['seo_title']) : null) : $old['seo_title'];
        $seoKeywords = array_key_exists('seo_keywords', $input) ? (is_string($input['seo_keywords']) ? trim($input['seo_keywords']) : null) : $old['seo_keywords'];
        $seoDescription = array_key_exists('seo_description', $input) ? (is_string($input['seo_description']) ? trim($input['seo_description']) : null) : $old['seo_description'];

        $parentId = $old['parent_id'] !== null ? (int) $old['parent_id'] : null;
        if (array_key_exists('parent_id', $input)) {
            if ($input['parent_id'] === null || $input['parent_id'] === '') {
                $parentId = null;
            } else {
                $parentId = Request::id($input['parent_id']);
            }
        }

        return Database::transaction(function (PDO $db) use ($admin, $id, $input, $old, $parentId, $name, $slug, $type, $headline, $description, $seoTitle, $seoKeywords, $seoDescription, $orderNum, $status): array {
            $stmt = $db->prepare('SELECT * FROM product_categories WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $current = $stmt->fetch();
            if (! $current) {
                throw new ApiException('Category not found', 404, 404);
            }
            $this->checkVersion($current, $input);
            $this->authorizeSuper($admin, $db);

            if ($parentId === $id) {
                throw new ApiException('Category cannot be its own parent', 422, 422);
            }

            if ($slug !== $current['slug']) {
                $s = $db->prepare('SELECT id FROM product_categories WHERE slug=? AND id != ?');
                $s->execute([$slug, $id]);
                if ($s->fetchColumn()) {
                    throw new ApiException('Category slug already exists', 409, 409);
                }
            }

            if ($parentId === null && $current['parent_id'] !== null) {
                // Assignments lock this category too: an existing binding cannot become a group.
                $s = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id=?');
                $s->execute([$id]);
                if ((int) $s->fetchColumn() > 0) {
                    throw new ApiException('Category with associated products cannot become a level 1 group', 422, 422);
                }
            }

            if ($parentId !== null) {
                // If making this node a child, ensure it does not already have children (which would make depth 3).
                $s = $db->prepare('SELECT COUNT(*) FROM product_categories WHERE parent_id=?');
                $s->execute([$id]);
                if ((int) $s->fetchColumn() > 0) {
                    throw new ApiException('Group with sub-categories cannot be made a child of another group', 422, 422);
                }

                // Verify parent exists and is a Level 1 group (parent_id IS NULL).
                $s = $db->prepare('SELECT id, parent_id FROM product_categories WHERE id=? FOR UPDATE');
                $s->execute([$parentId]);
                $parent = $s->fetch();
                if (! $parent) {
                    throw new ApiException('Parent category not found', 404, 404);
                }
                if ($parent['parent_id'] !== null) {
                    throw new ApiException('Tree depth cannot exceed 2 levels; parent must be a level 1 group', 422, 422);
                }
            }

            $stmt = $db->prepare('UPDATE product_categories SET parent_id=?, name=?, slug=?, type=?, headline=?, description=?, seo_title=?, seo_keywords=?, seo_description=?, order_num=?, status=? WHERE id=?');
            $stmt->execute([$parentId, $name, $slug, $type, $headline, $description, $seoTitle, $seoKeywords, $seoDescription, $orderNum, $status, $id]);

            $this->audit($db, $admin, $id, 'category_update', [
                'before' => array_intersect_key($old, array_flip(['parent_id', 'name', 'slug', 'type', 'order_num', 'status'])),
                'after' => ['parent_id' => $parentId, 'name' => $name, 'slug' => $slug, 'type' => $type, 'order_num' => $orderNum, 'status' => $status],
            ]);

            $updated = $this->read($id, $db);

            return $this->project($updated);
        });
    }

    public function delete(int $admin, int $id, array $input): array
    {
        $this->authorizeSuper($admin);

        return Database::transaction(function (PDO $db) use ($admin, $id, $input): array {
            $stmt = $db->prepare('SELECT * FROM product_categories WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $current = $stmt->fetch();
            if (! $current) {
                throw new ApiException('Category not found', 404, 404);
            }
            $this->checkVersion($current, $input);
            $this->authorizeSuper($admin, $db);

            // Refuse deletion if has sub-categories
            $s = $db->prepare('SELECT COUNT(*) FROM product_categories WHERE parent_id=? FOR UPDATE');
            $s->execute([$id]);
            if ((int) $s->fetchColumn() > 0) {
                throw new ApiException('Cannot delete category with sub-categories; remove sub-categories first', 409, 409);
            }

            // Refuse deletion if has associated products
            $s = $db->prepare('SELECT COUNT(*) FROM products WHERE category_id=? FOR UPDATE');
            $s->execute([$id]);
            if ((int) $s->fetchColumn() > 0) {
                throw new ApiException('Cannot delete category with associated products; reassign products first', 409, 409);
            }

            $stmt = $db->prepare('DELETE FROM product_categories WHERE id=?');
            $stmt->execute([$id]);

            $this->audit($db, $admin, $id, 'category_delete', [
                'deleted' => array_intersect_key($current, array_flip(['id', 'parent_id', 'name', 'slug', 'type'])),
            ]);

            return ['success' => true];
        });
    }

    /** Hidden categories remain valid for existing bindings, never for new assignments. */
    public function bindingContext(?int $id, ?int $retained = null, ?PDO $db = null): ?array
    {
        if ($id === null) {
            return null;
        }
        $s = ($db ?? Database::getInstance())->prepare('SELECT c.*, p.parent_id AS parent_parent_id, p.status AS parent_status, p.name AS parent_name
            FROM product_categories c JOIN product_categories p ON p.id=c.parent_id WHERE c.id=?'.($db ? ' FOR UPDATE' : ''));
        $s->execute([$id]);
        $c = $s->fetch();
        if (! $c || $c['parent_parent_id'] !== null || ($retained !== $id && ((int) $c['status'] !== 1 || (int) $c['parent_status'] !== 1))) {
            throw new ApiException('Invalid category: select an active level 2 category for a new assignment', 422, 422);
        }
        $parent = $this->read((int) $c['parent_id'], $db);

        return ['id' => $id, 'parent_id' => (int) $c['parent_id'],
            'path' => $c['parent_name'].' / '.$c['name'],
            'version' => $this->version($c), 'parent_version' => $this->version($parent)];
    }

    public function read(int $id, ?PDO $db = null): array
    {
        $s = ($db ?? Database::getInstance())->prepare('SELECT c.*,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS products_count,
            (SELECT COUNT(*) FROM product_categories sub WHERE sub.parent_id = c.id) AS subcategories_count
            FROM product_categories c WHERE c.id=?');
        $s->execute([$id]);
        $row = $s->fetch();
        if (! $row) {
            throw new ApiException('Category not found', 404, 404);
        }

        return $row;
    }

    public function version(array $c): string
    {
        $s = Database::getInstance()->prepare("SELECT COALESCE(MAX(id),0) FROM admin_audit_logs WHERE target_type='category' AND target_id=?");
        $s->execute([$c['id']]);

        return Security::requestFingerprint(json_encode([
            array_intersect_key($c, array_flip(['id', 'parent_id', 'name', 'slug', 'type', 'headline', 'description', 'seo_title', 'seo_keywords', 'seo_description', 'order_num', 'status'])),
            $s->fetchColumn(),
        ], JSON_THROW_ON_ERROR));
    }

    private function checkVersion(?array $c, array $input): void
    {
        if (! $c || ! hash_equals($this->version($c), Request::text($input, 'expected_version', 64, 64))) {
            throw new ApiException('Category changed; reload and review again', 409, 409);
        }
    }

    private function project(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'name' => $row['name'],
            'slug' => $row['slug'],
            'type' => $row['type'],
            'headline' => $row['headline'] ?? '',
            'description' => $row['description'] ?? '',
            'seo_title' => $row['seo_title'] ?? '',
            'seo_keywords' => $row['seo_keywords'] ?? '',
            'seo_description' => $row['seo_description'] ?? '',
            'order_num' => (int) $row['order_num'],
            'status' => (int) $row['status'],
            'products_count' => (int) ($row['products_count'] ?? 0),
            'subcategories_count' => (int) ($row['subcategories_count'] ?? 0),
            'version' => $this->version($row),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function authorizeSuper(int $admin, ?PDO $db = null): void
    {
        $s = ($db ?? Database::getInstance())->prepare("SELECT id FROM admins WHERE id=? AND status='active' AND role='super_admin'".($db ? ' FOR UPDATE' : ''));
        $s->execute([$admin]);
        if (! $s->fetchColumn()) {
            throw new ApiException('Forbidden: super administrator privileges required', 403, 403);
        }
    }

    private function authorizeRead(int $admin): void
    {
        $s = Database::getInstance()->prepare("SELECT id FROM admins WHERE id=? AND status='active'");
        $s->execute([$admin]);
        if (! $s->fetchColumn()) {
            throw new ApiException('Forbidden: administrator privileges required', 403, 403);
        }
    }

    private function audit(PDO $db, int $admin, int $id, string $action, array $context): void
    {
        $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,?, 'category', ?, ?, ?)")
            ->execute([$admin, $action, $id, json_encode($context, JSON_THROW_ON_ERROR), Security::getClientIp()]);
    }
}
