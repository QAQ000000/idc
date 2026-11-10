<?php

declare(strict_types=1);

namespace Plugins\providers\zjmf;

use App\Core\{RedisStore,Security};

/** Only authenticated login responses supply expiry. Never cache an opaque/unknown lifetime token. */
final class TokenCache
{
    private function key(array $c): string
    {
        if (! is_string($c['_connection_scope'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $c['_connection_scope'])) {
            throw new \RuntimeException('Unscoped connection cannot cache authentication');
        }

        return 'jianyun:zjmf:token:'.Security::requestFingerprint(json_encode([
            config('database.connections.mysql.database'), $c['_connection_scope'] ?? null, $c['endpoint'], $c['username'], $c['password'],
        ], JSON_THROW_ON_ERROR));
    }

    public function get(array $c): ?string
    {
        try {
            $raw = (new RedisStore())->command('GET', $this->key($c));
            $v = is_string($raw) ? json_decode(Security::decrypt($raw) ?? '{}', true, 8, JSON_THROW_ON_ERROR) : null;

            return is_array($v) && is_int($v['expires'] ?? null) && $v['expires'] > time() + 30 && is_string($v['token'] ?? null) ? $v['token'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function save(array $c, string $token): void
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return;
            }
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '{}', true, 16, JSON_THROW_ON_ERROR);
            $expires = $claims['exp'] ?? null;
            if (! is_int($expires) || $expires <= time() + 30) {
                return;
            }
            $ttl = min(300, $expires - time() - 30);
            (new RedisStore())->command('SET', $this->key($c), Security::encrypt(json_encode(['token' => $token, 'expires' => $expires], JSON_THROW_ON_ERROR)), 'EX', (string) $ttl);
        } catch (\Throwable) { /* Login still succeeded; no cache is required for correctness. */
        }
    }

    public function invalidate(array $c, string $rejected): void
    {
        try {
            $r = new RedisStore();
            $key = $this->key($c);
            $raw = $r->command('GET', $key);
            if (! is_string($raw)) {
                return;
            }$v = json_decode(Security::decrypt($raw) ?? '{}', true, 8, JSON_THROW_ON_ERROR);
            if (($v['token'] ?? null) !== $rejected) {
                return;
            }
            $r->command('EVAL', "if redis.call('GET',KEYS[1]) == ARGV[1] then return redis.call('DEL',KEYS[1]) else return 0 end", '1', $key, $raw);
        } catch (\Throwable) {
        }
    }
}
