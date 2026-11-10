<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Request,Security};
use PDO;

/** Local communication only. No financial, instance or upstream writes. */
final class TicketService
{
    private const STATES = ['open', 'in_progress', 'answered', 'customer_replied', 'closed'];

    private const PUBLIC_REPLY = "r.type='reply' AND ((r.user_id=t.user_id AND r.admin_id IS NULL) OR (r.user_id IS NULL AND r.admin_id IS NOT NULL))";

    private function actor(PDO $db, int $id, bool $admin, bool $lock = false): void
    {
        if (! $admin && $lock) {
            CustomerAccessService::assertActive($db, $id);

            return;
        }
        $s = $db->prepare('SELECT status'.($admin ? ',role' : '').' FROM '.($admin ? 'admins' : 'users').' WHERE id=?'.($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id]);
        $a = $s->fetch();
        if (! $a || $a['status'] !== 'active' || ($admin && ! in_array($a['role'], ['super_admin', 'finance', 'support', 'operator'], true))) {
            throw new ApiException('Active account required', 403, 403);
        }
    }

    private function fields(array $v, array $allowed): void
    {
        if (array_diff(array_keys($v), $allowed)) {
            throw new ApiException('Unsupported ticket field');
        }
    }

    private function text(array $v, string $key, int $max, bool $single = false): string
    {
        $text = trim(Request::text($v, $key, 1, $max * 4));
        if ($text === '' || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text, 'UTF-8') > $max
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) || ($single && preg_match('/[\r\n]/', $text))) {
            throw new ApiException('Invalid '.$key);
        }

        return $text;
    }

    private function key(array $v): string
    {
        $key = Request::text($v, 'request_key', 36, 36);
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key)) {
            throw new ApiException('request_key must be a UUID v4');
        }

        return $key;
    }

    private function page(array $q): array
    {
        $page = Request::id($q['page'] ?? 1);
        $size = Request::id($q['per_page'] ?? 20);
        if ($page > 100000 || $size > 100) {
            throw new ApiException('Pagination limit exceeded');
        }

        return [$page, $size];
    }

    private function ticket(PDO $db, int $actor, bool $admin, int $id, bool $lock = false): array
    {
        $s = $db->prepare('SELECT * FROM tickets WHERE id=?'.($admin ? '' : ' AND user_id=?').($lock ? ' FOR UPDATE' : ''));
        $s->execute($admin ? [$id] : [$id, $actor]);

        return $s->fetch() ?: throw new ApiException('Ticket not found', 404, 404);
    }

    public function listing(int $actor, bool $admin, array $q): array
    {
        $db = Database::getInstance();
        $this->actor($db, $actor, $admin);
        [$page,$size] = $this->page($q);
        $where = [];
        $args = [];
        if (! $admin) {
            $where[] = 't.user_id=?';
            $args[] = $actor;
        }
        if (isset($q['status']) && $q['status'] !== '') {
            if (! is_string($q['status']) || ! in_array($q['status'], self::STATES, true)) {
                throw new ApiException('Invalid ticket status');
            }$where[] = 't.status=?';
            $args[] = $q['status'];
        }
        if ($admin) {
            foreach (['ticket_id' => 't.id', 'user_id' => 't.user_id', 'instance_id' => 't.instance_id'] as $key => $col) {
                if (isset($q[$key]) && $q[$key] !== '') {
                    $where[] = $col.'=?';
                    $args[] = Request::id($q[$key]);
                }
            }
            if (isset($q['email']) && $q['email'] !== '') {
                $where[] = 'u.email=?';
                $args[] = Request::text($q, 'email', 1, 100);
            }
        }
        $from = ' FROM tickets t JOIN users u ON u.id=t.user_id LEFT JOIN instances i ON i.id=t.instance_id AND i.user_id=t.user_id'.($where ? ' WHERE '.implode(' AND ', $where) : '');
        $s = $db->prepare('SELECT COUNT(*)'.$from);
        $s->execute($args);
        $total = (int) $s->fetchColumn();
        $offset = ($page - 1) * $size;
        $s = $db->prepare('SELECT t.id,t.title,t.status,i.id AS instance_id,t.created_at,t.updated_at,t.closed_at'.($admin ? ',t.user_id,u.email' : '').$from." ORDER BY t.id DESC LIMIT $size OFFSET $offset");
        $s->execute($args);

        return ['items' => $s->fetchAll(), 'page' => $page, 'per_page' => $size, 'total' => $total];
    }

    public function instances(int $user, array $q): array
    {
        $db = Database::getInstance();
        $this->actor($db, $user, false);
        [$page,$size] = $this->page($q);
        $where = 'user_id=?';
        $args = [$user];
        if (isset($q['search']) && $q['search'] !== '') {
            $search = Request::text($q, 'search', 1, 100);
            $where .= " AND (CAST(id AS CHAR)=? OR name LIKE ? ESCAPE '!')";
            $args[] = $search;
            $args[] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        }
        $s = $db->prepare('SELECT COUNT(*) FROM instances WHERE '.$where);
        $s->execute($args);
        $total = (int) $s->fetchColumn();
        $offset = ($page - 1) * $size;
        $s = $db->prepare("SELECT id,name,status FROM instances WHERE $where ORDER BY id DESC LIMIT $size OFFSET $offset");
        $s->execute($args);

        return ['items' => $s->fetchAll(), 'page' => $page, 'per_page' => $size, 'total' => $total];
    }

    private function version(PDO $db, array $t, bool $current = false): string
    {
        $s = $db->prepare('SELECT r.id FROM ticket_replies r JOIN tickets t ON t.id=r.ticket_id WHERE t.id=? AND '.self::PUBLIC_REPLY.' ORDER BY r.id DESC LIMIT 1'.($current ? ' FOR UPDATE' : ''));
        $s->execute([$t['id']]);

        return Security::requestFingerprint(json_encode([$t['id'], $t['user_id'], $t['status'], $t['closed_at'], $s->fetchColumn() ?: 0], JSON_THROW_ON_ERROR));
    }

    public function detail(int $actor, bool $admin, int $id, array $q = []): array
    {
        return Database::transaction(function (PDO $db) use ($actor, $admin, $id, $q): array {
            $this->actor($db, $actor, $admin);
            $t = $this->ticket($db, $actor, $admin, $id);
            $last = ($q['page'] ?? null) === 'last';
            [$page,$size] = $this->page($last ? array_replace($q, ['page' => 1]) : $q);
            $from = ' FROM ticket_replies r JOIN tickets t ON t.id=r.ticket_id WHERE t.id=? AND '.self::PUBLIC_REPLY;
            $s = $db->prepare('SELECT COUNT(*)'.$from);
            $s->execute([$id]);
            $total = (int) $s->fetchColumn();
            if ($last) {
                $page = max(1, (int) ceil($total / $size));
            }$offset = ($page - 1) * $size;
            $s = $db->prepare('SELECT r.id,r.content,r.created_at,IF(r.admin_id IS NULL,\'customer\',\'admin\') AS author_type'.($admin ? ',r.admin_id,r.user_id' : '').$from." ORDER BY r.id LIMIT $size OFFSET $offset");
            $s->execute([$id]);
            $rows = $s->fetchAll();
            $s = $db->prepare('SELECT id,name,status,ip_address FROM instances WHERE id=? AND user_id=?');
            $s->execute([$t['instance_id'], $t['user_id']]);
            $instance = $s->fetch() ?: null;
            $out = array_intersect_key($t, array_flip(['id', 'title', 'status', 'created_at', 'updated_at', 'closed_at']));
            if ($admin) {
                $s = $db->prepare('SELECT id,email,status FROM users WHERE id=?');
                $s->execute([$t['user_id']]);
                $out['customer'] = $s->fetch();
            }

            return ['ticket' => $out, 'instance' => $instance, 'version' => $this->version($db, $t), 'replies' => ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $size]];
        });
    }

    private function replay(PDO $db, int $actor, bool $admin, string $key, string $hash): ?array
    {
        $s = $db->prepare('SELECT r.id,r.ticket_id,r.request_hash,t.user_id AS ticket_user FROM ticket_replies r JOIN tickets t ON t.id=r.ticket_id WHERE r.'.($admin ? 'admin_id' : 'user_id').'=? AND request_key=?');
        $s->execute([$actor, $key]);
        $r = $s->fetch();
        if (! $r) {
            return null;
        }if (! $admin && (int) $r['ticket_user'] !== $actor) {
            throw new ApiException('Ticket not found', 404, 404);
        }if (! hash_equals($r['request_hash'] ?? '', $hash)) {
            throw new ApiException('Request key already used for different ticket content', 409, 409);
        }

        return ['ticket_id' => (int) $r['ticket_id'], 'reply_id' => (int) $r['id'], 'replayed' => true];
    }

    private function record(PDO $db, int $actor, bool $admin, int $id, string $content, string $key, string $hash): int
    {
        $db->prepare('INSERT INTO ticket_replies(ticket_id,user_id,admin_id,content,request_key,request_hash) VALUES(?,?,?,?,?,?)')->execute([$id, $admin ? null : $actor, $admin ? $actor : null, $content, $key, $hash]);

        return (int) $db->lastInsertId();
    }

    public function create(int $user, array $v): array
    {
        $this->fields($v, ['title', 'content', 'instance_id', 'request_key']);
        $title = $this->text($v, 'title', 150, true);
        $content = $this->text($v, 'content', 10000);
        $key = $this->key($v);
        $instance = isset($v['instance_id']) ? Request::id($v['instance_id']) : null;
        $hash = Security::requestFingerprint(json_encode(['create', $title, $content, $instance], JSON_THROW_ON_ERROR));

        return Database::transaction(function (PDO $db) use ($user, $title, $content, $key, $instance, $hash): array {
            $this->actor($db, $user, false, true);
            if ($r = $this->replay($db, $user, false, $key, $hash)) {
                return $r;
            }
            if ($instance !== null) {
                $s = $db->prepare('SELECT id FROM instances WHERE id=? AND user_id=?');
                $s->execute([$instance, $user]);
                if (! $s->fetchColumn()) {
                    throw new ApiException('Instance not found', 404, 404);
                }
            }
            $db->prepare('INSERT INTO tickets(user_id,instance_id,title) VALUES(?,?,?)')->execute([$user, $instance, $title]);
            $id = (int) $db->lastInsertId();
            $reply = $this->record($db, $user, false, $id, $content, $key, $hash);
            (new TicketNotificationService())->enqueue($db, $id, $reply, $user, null);

            return ['ticket_id' => $id, 'reply_id' => $reply, 'replayed' => false];
        }, 3);
    }

    public function write(int $actor, bool $admin, int $id, array $v, bool $close = false): array
    {
        $this->fields($v, $close ? ['expected_version', 'request_key'] : ['content', 'request_key']);
        $key = $this->key($v);
        $value = $close ? Request::text($v, 'expected_version', 64, 64) : $this->text($v, 'content', 10000);
        $hash = Security::requestFingerprint(json_encode([$close ? 'close' : 'reply', $id, $value], JSON_THROW_ON_ERROR));

        return Database::transaction(function (PDO $db) use ($actor, $admin, $id, $key, $value, $hash, $close): array {
            $this->actor($db, $actor, $admin, true);
            if ($r = $this->replay($db, $actor, $admin, $key, $hash)) {
                return $r;
            }
            $t = $this->ticket($db, $actor, $admin, $id, true);
            if ($t['status'] === 'closed') {
                throw new ApiException('Ticket is closed; create a new ticket if more help is needed', 409, 409);
            }
            if ($close && ! hash_equals($this->version($db, $t, true), $value)) {
                throw new ApiException('New replies or status changes; reload before closing', 409, 409);
            }
            $content = $close ? ($admin ? '【结单】管理员关闭了工单。' : '【结单】客户关闭了工单。') : $value;
            $reply = $this->record($db, $actor, $admin, $id, $content, $key, $hash);
            $status = $close ? 'closed' : ($admin ? 'answered' : 'customer_replied');
            $db->prepare('UPDATE tickets SET status=?,closed_at='.($close ? 'UTC_TIMESTAMP()' : 'NULL').',updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$status, $id]);
            if ($admin) {
                $db->prepare('INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,?,?,?,?,?)')->execute([$actor, $close ? 'ticket.close' : 'ticket.reply', 'ticket', $id, json_encode(['reply_id' => $reply, 'before_status' => $t['status'], 'after_status' => $status], JSON_THROW_ON_ERROR), Security::getClientIp()]);
            }
            if (! $close) {
                (new TicketNotificationService())->enqueue($db, $id, $reply, (int) $t['user_id'], $admin ? $actor : null);
            }

            return ['ticket_id' => $id, 'reply_id' => $reply, 'replayed' => false];
        }, 3);
    }
}
