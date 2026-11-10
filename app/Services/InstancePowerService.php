<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use App\Plugins\Providers\{PowerDriverInterface, PowerState};
use PDO;

final class InstancePowerService
{
    private function owned(int $user, int $id, bool $lock = false): array
    {
        $s = Database::getInstance()->prepare('SELECT * FROM instances WHERE id=? AND user_id=?'.($lock ? ' FOR UPDATE' : ''));
        $s->execute([$id, $user]);

        return $s->fetch() ?: throw new ApiException('Instance not found', 404, 404);
    }

    public static function context(array $i): array
    {
        $snapshot = json_decode($i['config_snapshot'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);

        return ['provider_id' => (int) $i['provider_id'], 'remote_pid' => (int) ($snapshot['remote_pid'] ?? 0),
            'remote_instance_id' => $i['remote_instance_id'], 'billing_cycle' => $i['billing_cycle']];
    }

    public static function matchesContext(array $instance, array $payload): bool
    {
        foreach (self::context($instance) as $key => $value) {
            if (! array_key_exists($key, $payload) || $payload[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    public static function eligible(array $i): bool
    {
        return $i['status'] === 'active' && $i['remote_instance_id'] && $i['due_date'] && $i['due_date'] > gmdate('Y-m-d H:i:s');
    }

    private function version(array $i): string
    {
        $s = Database::getInstance()->prepare('SELECT COALESCE(MAX(id),0) FROM tasks WHERE instance_id=?');
        $s->execute([$i['id']]);

        return Security::requestFingerprint(json_encode([$i['id'], $i['user_id'], self::context($i), $i['status'], $i['due_date'], $i['config_snapshot'], (int) $s->fetchColumn()], JSON_THROW_ON_ERROR));
    }

    private static function clear(PDO $db, int $id): void
    {
        TaskGate::assertClear($db, $id);
        $s = $db->prepare("SELECT id FROM tasks WHERE instance_id=? AND status='pending' FOR UPDATE");
        $s->execute([$id]);
        if ($s->fetch()) {
            throw new ApiException('Instance has a queued operation', 409, 409);
        }
    }

    private function inspect(array $i): PowerState
    {
        if (! self::eligible($i)) {
            return new PowerState('unsupported');
        }
        try {
            [$driver,$credentials] = (new ProviderService())->resolve((int) $i['provider_id']);

            return $driver instanceof PowerDriverInterface ? $driver->inspectPower(self::context($i), $credentials) : new PowerState('unsupported');
        } catch (\Throwable) {
            return new PowerState('unknown');
        }
    }

    private static function actions(PowerState $state): array
    {
        return array_values(array_intersect($state->actions, match ($state->state) {
            'on' => ['stop', 'reboot'],'off' => ['start'],default => []
        }));
    }

    /** Only explicit refresh performs upstream reads; the ordinary instance list remains local. */
    public function runtime(int $user, int $id, bool $tasksOnly = false): array
    {
        $i = $this->owned($user, $id);
        $version = $tasksOnly ? '' : $this->version($i);
        $state = $tasksOnly ? null : $this->inspect($i);

        return Database::transaction(function (PDO $db) use ($user, $id, $version, $state, $tasksOnly): array {
            $i = $this->owned($user, $id, true);
            $blocked = false;
            try {
                self::clear($db, $id);
            } catch (ApiException $e) {
                if ($e->httpStatus !== 409) {
                    throw $e;
                } $blocked = true;
            }
            $s = $db->prepare("SELECT id,status,last_error,created_at,updated_at,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.power_action')) AS action FROM tasks WHERE instance_id=? AND action='power' ORDER BY id DESC LIMIT 10");
            $s->execute([$id]);
            $tasks = $s->fetchAll();
            foreach ($tasks as &$t) {
                $t['id'] = (int) $t['id'];
                $t['result'] = match ($t['status']) {
                    'success' => 'accepted','failed' => $t['last_error'] === 'ADMIN_VERIFIED:POWER_NOT_EXECUTED' ? 'not_executed' : 'needs_review',default => $t['status']
                };
                unset($t['last_error']);
            }
            unset($t);
            if ($tasksOnly) {
                return ['blocked' => $blocked, 'tasks' => $tasks];
            }
            $current = $this->version($i);
            $actions = self::eligible($i) && ! $blocked && hash_equals($version, $current) ? self::actions($state) : [];
            $claims = ['user' => $user, 'instance' => $id, 'version' => $current, 'expires' => time() + 120, 'actions' => $actions];
            $body = rtrim(strtr(base64_encode(json_encode($claims, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

            return ['state' => hash_equals($version, $current) ? $state->state : 'unknown', 'checked_at' => gmdate('Y-m-d H:i:s'),
                'actions' => $actions, 'blocked' => $blocked, 'operation_token' => $body.'.'.Security::requestFingerprint('power:'.$body), 'tasks' => $tasks];
        });
    }

    public function submit(int $user, int $id, array $input): array
    {
        if (\App\Middlewares\AuthMiddleware::isImpersonated()) {
            throw new ApiException('Power writes require the owner session', 403, 403);
        }
        $action = Request::text($input, 'action', 1, 10);
        if (! in_array($action, ['start', 'stop', 'reboot'], true)) {
            throw new ApiException('Unsupported power action');
        }
        $key = Request::text($input, 'request_key', 16, 64);
        if (! preg_match('/^[a-zA-Z0-9_-]+$/D', $key)) {
            throw new ApiException('Invalid request key');
        }
        $raw = Request::text($input, 'operation_token', 1, 2000);
        $parts = explode('.', $raw);
        if (count($parts) !== 2 || ! hash_equals(Security::requestFingerprint('power:'.$parts[0]), $parts[1])) {
            throw new ApiException('Invalid operation confirmation', 409, 409);
        }
        $claims = json_decode(base64_decode(strtr($parts[0], '-_', '+/'), true) ?: '{}', true, 16, JSON_THROW_ON_ERROR);
        if (($claims['user'] ?? 0) !== $user || ($claims['instance'] ?? 0) !== $id || ($claims['expires'] ?? 0) <= time()
            || ! in_array($action, $claims['actions'] ?? [], true)) {
            throw new ApiException('Operation confirmation expired or unavailable', 409, 409);
        }

        return Database::transaction(function (PDO $db) use ($user, $id, $claims, $action, $key): array {
            $i = $this->owned($user, $id, true);
            if (! self::eligible($i) || ! hash_equals($this->version($i), $claims['version'])) {
                throw new ApiException('Instance or operation changed; refresh first', 409, 409);
            }
            self::clear($db, $id);
            CustomerAccessService::assertActive($db, $user);
            $s = $db->prepare('SELECT status FROM providers WHERE id=? FOR UPDATE');
            $s->execute([$i['provider_id']]);
            if ((int) $s->fetchColumn() !== 1) {
                throw new ApiException('Provider unavailable', 409, 409);
            }
            $identity = 'instance_power:'.$id.':'.$key;
            $s = $db->prepare('SELECT id FROM tasks WHERE idempotency_key=?');
            $s->execute([$identity]);
            if ($s->fetch()) {
                throw new ApiException('Power request already submitted', 409, 409);
            }
            $payload = self::context($i) + ['power_action' => $action, 'requested_by' => $user];
            $db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,payload,max_retries) VALUES(?,NULL,?,'power',?,0)")
                ->execute([$id, $identity, json_encode($payload, JSON_THROW_ON_ERROR)]);

            return ['task_id' => (int) $db->lastInsertId(), 'status' => 'pending', 'action' => $action];
        });
    }
}
