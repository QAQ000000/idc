<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Request,Security};
use PDO;

/** Account controls never acquire resource, invoice or task locks. */
final class CustomerAccountService
{
    private function authorize(PDO $db, int $actor, int $session): void
    {
        $s = $db->prepare('SELECT status,role,two_factor_secret FROM admins WHERE id=? FOR UPDATE');
        $s->execute([$actor]);
        $a = $s->fetch();
        if (! $a || $a['status'] !== 'active' || ! empty($a['two_factor_secret']) || AdminAuthService::sessionVersion($db, $actor, true) !== $session) {
            throw new ApiException('Administrator session revoked; log in again', 401, 401);
        }
        if ($a['role'] !== 'super_admin') {
            throw new ApiException('Active super_admin required', 403, 403);
        }
    }

    private function row(PDO $db, int $id): array
    {
        $s = $db->prepare('SELECT id,email,status,auth_version FROM users WHERE id=? FOR UPDATE');
        $s->execute([$id]);

        return $s->fetch() ?: throw new ApiException('Customer not found', 404, 404);
    }

    private function projection(array $u): array
    {
        return ['id' => (int) $u['id'], 'email' => $u['email'], 'status' => $u['status'],
            'version' => Security::requestFingerprint(json_encode([(int) $u['id'], $u['email'], $u['status'], (int) $u['auth_version']], JSON_THROW_ON_ERROR))];
    }

    public function detail(int $actor, int $session, int $id, ?string $key = null): array
    {
        if ($key !== null) {
            $this->requestKey(['request_key' => $key]);
        }

        return Database::transaction(function (PDO $db) use ($actor, $session, $id, $key): array {
            $this->authorize($db, $actor, $session);
            $u = $this->row($db, $id);
            $sql = "SELECT l.id,l.admin_id,a.username,l.action,l.context,l.created_at FROM admin_audit_logs l JOIN admins a ON a.id=l.admin_id WHERE l.target_type='user' AND l.target_id=? AND l.action IN ('customer_status_update','customer_password_reset')";
            $s = $db->prepare($sql.' ORDER BY l.id DESC LIMIT 20');
            $s->execute([$id]);
            $audit = $s->fetchAll();
            $operation = null;
            if ($key !== null) {
                $s = $db->prepare($sql." AND l.admin_id=? AND JSON_UNQUOTE(JSON_EXTRACT(l.context,'$.request_key'))=? ORDER BY l.id DESC LIMIT 1");
                $s->execute([$id, $actor, $key]);
                $operation = $s->fetch() ?: null;
            }
            $project = static function (array $r): array {
                $r['context'] = array_intersect_key(json_decode($r['context'], true, 16, JSON_THROW_ON_ERROR), array_flip(['evidence', 'request_key', 'before_status', 'after_status', 'sessions_revoked']));

                return $r;
            };

            return ['account' => $this->projection($u), 'audit' => array_map($project, $audit), 'operation' => $operation ? $project($operation) : null];
        });
    }

    private function requestKey(array $v): string
    {
        $key = Request::text($v, 'request_key', 36, 36);
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $key)) {
            throw new ApiException('request_key must be a UUID v4');
        }

        return $key;
    }

    public function change(int $actor, int $session, int $id, array $v, bool $password = false): array
    {
        // Authorize before expensive password hashing; authorization is repeated after acquiring write locks.
        Database::transaction(fn (PDO $db) => $this->authorize($db, $actor, $session));
        if (array_diff(array_keys($v), ['expected_version', 'evidence', 'request_key', 'confirmed', $password ? 'new_password' : 'status'])) {
            throw new ApiException('Unsupported customer account field');
        }
        if (($v['confirmed'] ?? null) !== true) {
            throw new ApiException('Explicit account change confirmation required');
        }
        $expected = Request::text($v, 'expected_version', 64, 64);
        $key = $this->requestKey($v);
        $evidence = trim(Request::text($v, 'evidence', 10, 1000));
        if (strlen($evidence) < 10 || ! mb_check_encoding($evidence, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $evidence)) {
            throw new ApiException('Invalid operation evidence');
        }
        $hash = null;
        $status = null;
        if ($password) {
            $plain = Request::text($v, 'new_password', 12, 72);
            if (str_contains($evidence, $plain)) {
                throw new ApiException('Do not include the password in operation evidence');
            }$hash = Security::hashPassword($plain);
        } else {
            $status = Request::text($v, 'status', 1, 20);
            if (! in_array($status, ['active', 'suspended'], true)) {
                throw new ApiException('Invalid customer status');
            }
        }

        return Database::transaction(function (PDO $db) use ($actor, $session, $id, $expected, $key, $evidence, $hash, $status, $password): array {
            $this->authorize($db, $actor, $session);
            $u = $this->row($db, $id);
            if (! hash_equals($this->projection($u)['version'], $expected)) {
                throw new ApiException('Customer account changed; reload and confirm again', 409, 409);
            }
            $s = $db->prepare("SELECT id FROM admin_audit_logs WHERE target_type='user' AND target_id=? AND action IN ('customer_status_update','customer_password_reset') AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.request_key'))=? LIMIT 1 FOR UPDATE");
            $s->execute([$id, $key]);
            if ($s->fetchColumn()) {
                throw new ApiException('Account operation key already used', 409, 409);
            }
            if (! $password && $u['status'] === $status) {
                throw new ApiException('Customer status is unchanged', 409, 409);
            }
            $db->prepare($password ? 'UPDATE users SET password=?,auth_version=auth_version+1 WHERE id=?' : 'UPDATE users SET status=?,auth_version=auth_version+1 WHERE id=?')->execute([$password ? $hash : $status, $id]);
            $context = ['request_key' => $key, 'evidence' => $evidence, 'sessions_revoked' => true];
            if (! $password) {
                $context += ['before_status' => $u['status'], 'after_status' => $status];
            }
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,?,'user',?,?,?)")
                ->execute([$actor, $password ? 'customer_password_reset' : 'customer_status_update', $id, json_encode($context, JSON_THROW_ON_ERROR), Security::getClientIp()]);

            return ['customer_id' => $id, 'request_key' => $key, 'sessions_revoked' => true];
        }, 3);
    }
}
