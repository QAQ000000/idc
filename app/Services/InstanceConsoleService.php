<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, RedisStore, Security};
use App\Plugins\Providers\ConsoleDriverInterface;
use PDO;

final class InstanceConsoleService
{
    public function principal(string $token): int
    {
        $p = Security::verifyToken($token);
        if (! $p || ($p['sub_type'] ?? 'user') !== 'user' || ! empty($p['is_impersonated'])) {
            throw new ApiException('Console requires an owner session', 401, 401);
        }
        $s = Database::getInstance()->prepare('SELECT status,auth_version FROM users WHERE id=?');
        $s->execute([$p['uid']]);
        $user = $s->fetch();
        if (! $user || $user['status'] !== 'active') {
            throw new ApiException('Account unavailable', 403, 403);
        }
        Security::assertUserVersion($p, (int) $user['auth_version']);

        return $p['uid'];
    }

    public function eligible(int $user, int $id): array
    {
        return Database::transaction(function (PDO $db) use ($user, $id): array {
            $s = $db->prepare('SELECT * FROM instances WHERE id=? AND user_id=? FOR UPDATE');
            $s->execute([$id, $user]);
            $i = $s->fetch() ?: throw new ApiException('Instance not found', 404, 404);
            if (! InstancePowerService::eligible($i)) {
                throw new ApiException('Console unavailable for this instance', 409, 409);
            }
            TaskGate::assertClear($db, $id);
            $s = $db->prepare("SELECT id FROM tasks WHERE instance_id=? AND status='pending' LIMIT 1");
            $s->execute([$id]);
            if ($s->fetch()) {
                throw new ApiException('Instance operation queued', 409, 409);
            }
            $s = $db->prepare('SELECT status FROM providers WHERE id=?');
            $s->execute([$i['provider_id']]);
            if ((int) $s->fetchColumn() !== 1) {
                throw new ApiException('Provider unavailable', 409, 409);
            }

            return $i;
        });
    }

    public function binding(array $i): string
    {
        return Security::requestFingerprint(json_encode([$i['user_id'], InstancePowerService::context($i), $i['status'], $i['due_date'], $i['config_snapshot']], JSON_THROW_ON_ERROR));
    }

    public function issue(string $token, int $id): array
    {
        if (! config('console.targets')) {
            throw new ApiException('Console is not configured', 503, 503);
        }
        $user = $this->principal($token);
        $i = $this->eligible($user, $id);
        $redis = new RedisStore();
        if (! $redis->command('SET', 'console:rate:'.$user.':'.$id, '1', 'NX', 'EX', '5')) {
            throw new ApiException('Please wait before reconnecting', 429, 429);
        }
        try {
            [$d,$c] = (new ProviderService())->resolve((int) $i['provider_id']);
            if (! $d instanceof ConsoleDriverInterface) {
                throw new \RuntimeException();
            }
            $target = $d->openConsole(InstancePowerService::context($i), $c);
            ConsoleTargetPolicy::validate($target->url);
            if (rtrim(strtolower(parse_url($target->url, PHP_URL_HOST)), '.') === rtrim(strtolower(parse_url($c['endpoint'], PHP_URL_HOST)), '.')) {
                throw new \RuntimeException('Console must use a separate cloud domain');
            }
        } catch (\Throwable) {
            throw new ApiException('控制台暂不可用，请稍后重试或联系客服', 409, 409);
        }
        $fresh = $this->eligible($this->principal($token), $id);
        if (! hash_equals($this->binding($i), $this->binding($fresh))) {
            throw new ApiException('Instance changed; refresh first', 409, 409);
        }
        $claims = Security::verifyToken($token) ?? throw new ApiException('Session expired', 401, 401);

        return ['websocket_url' => $target->url, 'password' => $target->password, 'binding' => $this->binding($i),
            'client_disconnect_at' => min(time() + 900, $claims['exp'])];
    }

    /** Client-side continuity check. Direct upstream credentials cannot be revoked by this endpoint. */
    public function check(string $token, int $id): array
    {
        $i = $this->eligible($this->principal($token), $id);

        return ['binding' => $this->binding($i)];
    }
}
