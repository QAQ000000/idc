<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Request,Security};
use Illuminate\Support\Facades\RateLimiter;
use PDO;

/** Low-volume account control; the immutable safety audit is also the session revocation fact. */
final class AdminAccountService
{
    public const ROLES = ['super_admin', 'finance', 'support', 'operator'];

    private const FIELDS = ['id', 'username', 'email', 'role', 'status', 'last_login_at', 'last_login_ip', 'created_at'];

    private function row(PDO $db, int $id): array
    {
        $s = $db->prepare('SELECT * FROM admins WHERE id=?');
        $s->execute([$id]);

        return $s->fetch() ?: throw new ApiException('Administrator not found', 404, 404);
    }

    private static function secondFactor(array $a): bool
    {
        return ($a['two_factor_secret'] ?? null) !== null && $a['two_factor_secret'] !== '';
    }

    private static function supportedPassword(array $a): bool
    {
        return password_get_info($a['password'])['algo'] !== null || preg_match('/^[a-f0-9]{32}$/D', $a['password']) === 1;
    }

    private static function loginable(array $a): bool
    {
        return $a['status'] === 'active' && $a['role'] === 'super_admin' && ! self::secondFactor($a)
            && self::supportedPassword($a);
    }

    private function publicRow(PDO $db, array $a): array
    {
        $result = array_intersect_key($a, array_flip(self::FIELDS));
        $result['second_factor_configured'] = self::secondFactor($a);
        $result['login_supported'] = ! self::secondFactor($a) && self::supportedPassword($a);
        $result['version'] = $this->version($db, $a);

        return $result;
    }

    private function version(PDO $db, array $a, bool $lock = false): string
    {
        return Security::requestFingerprint(json_encode([(int) $a['id'], $a['username'], $a['email'], $a['role'], $a['status'], self::secondFactor($a), AdminAuthService::sessionVersion($db, (int) $a['id'], $lock)], JSON_THROW_ON_ERROR));
    }

    private function authorize(PDO $db, int $actor, int $session, bool $super = true, ?array $locked = null): array
    {
        $a = $locked ?? $this->row($db, $actor);
        if ($a['status'] !== 'active' || self::secondFactor($a) || AdminAuthService::sessionVersion($db, $actor, $locked !== null) !== $session) {
            throw new ApiException('Administrator session revoked; log in again', 401, 401);
        }
        if ($super && $a['role'] !== 'super_admin') {
            throw new ApiException('Active super_admin required', 403, 403);
        }

        return $a;
    }

    /** All account writers share this order; no instance, wallet or task row is acquired here. */
    private function lockAccounts(PDO $db): array
    {
        $rows = $db->query('SELECT * FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        $out = [];
        foreach ($rows as $a) {
            $out[(int) $a['id']] = $a;
        }

        return $out;
    }

    private function assertVersion(PDO $db, array $a, string $expected): void
    {
        if (! hash_equals($this->version($db, $a, true), $expected)) {
            throw new ApiException('Administrator changed; reload and confirm again', 409, 409);
        }
    }

    private function keepSuper(array $accounts): void
    {
        foreach ($accounts as $a) {
            if (self::loginable($a)) {
                return;
            }
        }
        throw new ApiException('At least one enabled super administrator able to log in must remain', 409, 409);
    }

    private function audit(PDO $db, int $actor, int $target, string $action, array $context): void
    {
        $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,?,'admin',?,?,?)")
            ->execute([$actor, $action, $target, json_encode($context, JSON_THROW_ON_ERROR), Security::getClientIp()]);
    }

    private function fields(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $k) {
            if (! in_array($k, $allowed, true)) {
                throw new ApiException('Unsupported administrator field');
            }
        }
    }

    private function evidence(array $input): string
    {
        $v = trim(Request::text($input, 'evidence', 10, 1000));
        if (strlen($v) < 10) {
            throw new ApiException('Change evidence requires 10–1000 bytes');
        }

        return $v;
    }

    private function prepare(array $input): array
    {
        $username = Request::text($input, 'username', 1, 50);
        $email = strtolower(trim(Request::text($input, 'email', 3, 100)));
        $role = Request::text($input, 'role', 1, 20);
        $status = array_key_exists('status', $input) ? $input['status'] : 'active';
        if ($username !== trim($username) || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, self::ROLES, true) || ! in_array($status, ['active', 'disabled'], true)) {
            throw new ApiException('Invalid administrator');
        }

        return ['username' => $username, 'email' => $email, 'role' => $role, 'status' => $status, 'password' => Security::hashPassword(Request::text($input, 'password', 12, 72))];
    }

    private function insert(PDO $db, array $a, int $actor = 0): int
    {
        $s = $db->prepare('SELECT id FROM admins WHERE username=? OR email=? FOR UPDATE');
        $s->execute([$a['username'], $a['email']]);
        if ($s->fetch()) {
            throw new ApiException('Administrator username or email already exists', 409, 409);
        }
        $db->prepare('INSERT INTO admins(username,email,password,role,status) VALUES(?,?,?,?,?)')->execute([$a['username'], $a['email'], $a['password'], $a['role'], $a['status']]);
        $id = (int) $db->lastInsertId();
        $this->audit($db, $actor ?: $id, $id, $actor ? 'admin_account_create' : 'admin_provision', array_intersect_key($a, array_flip(['username', 'email', 'role', 'status'])) + ['source' => $actor ? 'web' : 'local_cli']);

        return $id;
    }

    public function createLocal(array $input): int
    {
        if (PHP_SAPI !== 'cli') {
            throw new ApiException('Local administrator provisioning requires CLI', 403, 403);
        }
        $this->fields($input, ['username', 'email', 'role', 'password', 'status']);
        $a = $this->prepare($input);

        return Database::transaction(function (PDO $db) use ($a): int {
            $this->lockAccounts($db);

            return $this->insert($db, $a);
        }, 3);
    }

    public function listing(int $actor, int $session, array $query): array
    {
        $db = Database::getInstance();
        $this->authorize($db, $actor, $session);
        $page = Request::id($query['page'] ?? 1);
        $size = Request::id($query['per_page'] ?? 20);
        $role = $query['role'] ?? 'all';
        $status = $query['status'] ?? 'all';
        $q = trim(Request::text($query + ['q' => ''], 'q', 0, 100));
        if ($page > 100000 || $size > 100 || ! in_array($role, ['all', ...self::ROLES], true) || ! in_array($status, ['all', 'active', 'disabled'], true)) {
            throw new ApiException('Invalid administrator filter');
        }
        $where = [];
        $args = [];
        if ($role !== 'all') {
            $where[] = 'role=?';
            $args[] = $role;
        }if ($status !== 'all') {
            $where[] = 'status=?';
            $args[] = $status;
        }
        if ($q !== '') {
            $where[] = "(username LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!')";
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            array_push($args, $like, $like);
        }
        $sql = ' FROM admins'.($where ? ' WHERE '.implode(' AND ', $where) : '');
        $s = $db->prepare('SELECT COUNT(*)'.$sql);
        $s->execute($args);
        $total = (int) $s->fetchColumn();
        $offset = ($page - 1) * $size;
        $s = $db->prepare('SELECT *'.$sql." ORDER BY id DESC LIMIT $size OFFSET $offset");
        $s->execute($args);

        return ['items' => array_map(fn ($a) => $this->publicRow($db, $a), $s->fetchAll()), 'total' => $total, 'page' => $page, 'per_page' => $size];
    }

    public function detail(int $actor, int $session, int $id): array
    {
        $db = Database::getInstance();
        $this->authorize($db, $actor, $session);
        $a = $this->row($db, $id);
        $s = $db->prepare("SELECT l.id,l.action,l.admin_id,a.username,l.context,l.created_at FROM admin_audit_logs l JOIN admins a ON a.id=l.admin_id WHERE l.target_type='admin' AND l.target_id=? AND l.action IN ('admin_provision','admin_account_create','admin_account_update','admin_password_change','admin_password_reset') ORDER BY l.id DESC LIMIT 20");
        $s->execute([$id]);
        $audit = $s->fetchAll();
        foreach ($audit as &$r) {
            $r['context'] = array_intersect_key(json_decode($r['context'], true, 32, JSON_THROW_ON_ERROR), array_flip(['username', 'email', 'role', 'status', 'source', 'before', 'after', 'evidence', 'sessions_revoked']));
        }

        return ['account' => $this->publicRow($db, $a), 'audit' => $audit];
    }

    public function security(int $actor, int $session): array
    {
        $db = Database::getInstance();
        $a = $this->authorize($db, $actor, $session, false);

        return ['account' => $this->publicRow($db, $a)];
    }

    public function create(int $actor, int $session, array $input): array
    {
        $this->authorize(Database::getInstance(), $actor, $session);
        $this->fields($input, ['username', 'email', 'role', 'status', 'password']);
        $a = $this->prepare($input);

        return Database::transaction(function (PDO $db) use ($actor, $session, $a): array {
            $rows = $this->lockAccounts($db);
            $this->authorize($db, $actor, $session, true, $rows[$actor] ?? null);
            $id = $this->insert($db, $a, $actor);

            return ['admin_id' => $id];
        }, 3);
    }

    public function update(int $actor, int $session, int $id, array $input): array
    {
        $this->authorize(Database::getInstance(), $actor, $session);
        $this->fields($input, ['role', 'status', 'expected_version', 'evidence']);
        $role = Request::text($input, 'role', 1, 20);
        $status = Request::text($input, 'status', 1, 20);
        $expected = Request::text($input, 'expected_version', 64, 64);
        $evidence = $this->evidence($input);
        if (! in_array($role, self::ROLES, true) || ! in_array($status, ['active', 'disabled'], true)) {
            throw new ApiException('Invalid administrator role or status');
        }

        return Database::transaction(function (PDO $db) use ($actor, $session, $id, $role, $status, $expected, $evidence): array {
            $rows = $this->lockAccounts($db);
            $this->authorize($db, $actor, $session, true, $rows[$actor] ?? null);
            $a = $rows[$id] ?? throw new ApiException('Administrator not found', 404, 404);
            $this->assertVersion($db, $a, $expected);
            if ($role === $a['role'] && $status === $a['status']) {
                throw new ApiException('Administrator is unchanged', 409, 409);
            }
            $rows[$id] = array_replace($a, ['role' => $role, 'status' => $status]);
            $this->keepSuper($rows);
            $db->prepare('UPDATE admins SET role=?,status=? WHERE id=?')->execute([$role, $status, $id]);
            $this->audit($db, $actor, $id, 'admin_account_update', ['before' => ['role' => $a['role'], 'status' => $a['status']], 'after' => ['role' => $role, 'status' => $status], 'evidence' => $evidence, 'sessions_revoked' => true]);

            return ['admin_id' => $id, 'sessions_revoked' => true, 'self_changed' => $actor === $id];
        }, 3);
    }

    public function resetPassword(int $actor, int $session, int $id, array $input): array
    {
        $this->authorize(Database::getInstance(), $actor, $session);
        $this->fields($input, ['new_password', 'expected_version', 'evidence', 'confirmed']);
        if ($actor === $id) {
            throw new ApiException('Change your own password using your current password', 400, 400);
        }if (($input['confirmed'] ?? null) !== true) {
            throw new ApiException('Explicit password reset confirmation required');
        }
        $expected = Request::text($input, 'expected_version', 64, 64);
        $evidence = $this->evidence($input);
        $hash = Security::hashPassword(Request::text($input, 'new_password', 12, 72));

        return Database::transaction(function (PDO $db) use ($actor, $session, $id, $expected, $evidence, $hash): array {
            $rows = $this->lockAccounts($db);
            $this->authorize($db, $actor, $session, true, $rows[$actor] ?? null);
            $a = $rows[$id] ?? throw new ApiException('Administrator not found', 404, 404);
            $this->assertVersion($db, $a, $expected);
            $rows[$id]['password'] = $hash;
            $this->keepSuper($rows);
            $db->prepare('UPDATE admins SET password=? WHERE id=?')->execute([$hash, $id]);
            $this->audit($db, $actor, $id, 'admin_password_reset', ['evidence' => $evidence, 'sessions_revoked' => true]);

            return ['admin_id' => $id, 'sessions_revoked' => true];
        }, 3);
    }

    public function changePassword(int $actor, int $session, array $input): array
    {
        $db = Database::getInstance();
        $a = $this->authorize($db, $actor, $session, false);
        $this->fields($input, ['current_password', 'new_password', 'expected_version']);
        $old = Request::text($input, 'current_password', 1, 72);
        $new = Request::text($input, 'new_password', 12, 72);
        $expected = Request::text($input, 'expected_version', 64, 64);
        $rate = 'admin-password:'.$actor;
        if (RateLimiter::tooManyAttempts($rate, 5)) {
            throw new ApiException('Too many password attempts; try again in one minute', 429, 429);
        }RateLimiter::hit($rate, 60);
        if (str_contains($old, "\0") || ! Security::verifyPassword($old, $a['password'])) {
            throw new ApiException('Current password is incorrect', 400, 400);
        }
        $hash = Security::hashPassword($new);

        return Database::transaction(function (PDO $db) use ($actor, $session, $a, $hash, $expected): array {
            $rows = $this->lockAccounts($db);
            $current = $this->authorize($db, $actor, $session, false, $rows[$actor] ?? null);
            $this->assertVersion($db, $current, $expected);
            if (! hash_equals($a['password'], $current['password'])) {
                throw new ApiException('Credentials changed; reload and confirm again', 409, 409);
            }
            $rows[$actor]['password'] = $hash;
            $this->keepSuper($rows);
            $db->prepare('UPDATE admins SET password=? WHERE id=?')->execute([$hash, $actor]);
            $this->audit($db, $actor, $actor, 'admin_password_change', ['sessions_revoked' => true]);

            return ['sessions_revoked' => true];
        }, 3);
    }
}
