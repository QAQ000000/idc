<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{Database, Security};
use App\Plugins\Providers\ProviderRegistry;

final class ProviderService
{
    public function __construct(private readonly ?ProviderRegistry $registry = null) {}

    public function resolve(int $id): array
    {
        $s = Database::getInstance()->prepare('SELECT * FROM providers WHERE id = ?');
        $s->execute([$id]);
        $p = $s->fetch();
        if (! $p || (int) $p['status'] !== 1) {
            throw new \RuntimeException('Provider disabled');
        }
        $credentials = json_decode($p['credentials'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        foreach ($credentials as &$v) {
            if (! is_string($v) || ($v = Security::decrypt($v)) === null) {
                throw new \RuntimeException('Provider credentials unavailable');
            }
        }
        unset($v);
        // Reserved internal scope: connection identity plus encrypted credential revision; never project to clients.
        $credentials['_connection_scope'] = Security::requestFingerprint(json_encode([$p['id'], $p['driver'], $p['endpoint'], $p['credentials']], JSON_THROW_ON_ERROR));
        $credentials['endpoint'] = $p['endpoint'];

        return [($this->registry ?? ProviderRegistry::installed())->get($p['driver']), $credentials];
    }
}
