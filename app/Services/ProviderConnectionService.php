<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Request,Security};
use App\Plugins\Providers\{ConnectionConfigInterface,ProviderRegistry};
use PDO;

/** Shared by the web domain service and trusted CLI installer. Never modifies an existing namespace. */
final class ProviderConnectionService
{
    public function __construct(private readonly ?ProviderRegistry $registry = null) {}

    public function prepare(array $input): array
    {
        foreach (array_keys($input) as $key) {
            if (! in_array($key, ['name', 'driver', 'endpoint', 'credentials'], true)) {
                throw new ApiException('Unsupported connection field');
            }
        }
        $name = trim(Request::text($input, 'name', 1, 50));
        $id = Request::text($input, 'driver', 1, 50);
        if ($name === '') {
            throw new ApiException('Invalid connection name');
        }
        try {
            $driver = ($this->registry ?? ProviderRegistry::installed())->get($id);
        } catch (\Throwable) {
            throw new ApiException('Installed connection driver unavailable');
        }
        if (! $driver instanceof ConnectionConfigInterface) {
            throw new ApiException('Driver does not support connection configuration', 409, 409);
        }
        try {
            $config = $driver->validateConnection($input);
        } catch (\Throwable) {
            throw new ApiException('Invalid driver connection parameters');
        }

        return ['name' => $name, 'driver' => $id, 'endpoint' => $config['endpoint'], 'credentials' => $config['credentials']];
    }

    /** Internal primitive: caller owns authorization, audit and the enclosing transaction. */
    public function persist(PDO $db, array $prepared): int
    {
        if (! $db->inTransaction()) {
            throw new \LogicException('Connection persistence requires transaction');
        }
        $encrypted = [];
        foreach ($prepared['credentials'] as $key => $value) {
            $encrypted[$key] = Security::encrypt($value);
        }
        $db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')
            ->execute([$prepared['name'], $prepared['driver'], $prepared['endpoint'], json_encode($encrypted, JSON_THROW_ON_ERROR)]);

        return (int) $db->lastInsertId();
    }
}
