<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,ReadObservationCache};
use App\Plugins\Providers\{PowerObservationDriverInterface,PowerState,ReinstallDriverInterface,ReinstallOptions};

/** Display-only projections. All operation preparation, submit and Worker inspections remain fresh. */
final class InstanceDisplayService
{
    private function instance(int $user, int $id): array
    {
        $s = Database::getInstance()->prepare('SELECT * FROM instances WHERE id=? AND user_id=?');
        $s->execute([$id, $user]);

        return $s->fetch() ?: throw new ApiException('Instance not found', 404, 404);
    }

    private function scope(array $i, array $credentials, string $kind): string
    {
        $s = Database::getInstance()->prepare('SELECT id,status,execution_token,updated_at FROM tasks WHERE instance_id=? ORDER BY id DESC LIMIT 1');
        $s->execute([$i['id']]);

        return ReadObservationCache::key(json_encode([$kind, $i['id'], $i['user_id'], $credentials['_connection_scope'],
            InstancePowerService::context($i), $i['status'], $i['due_date'], $i['config_snapshot'], $s->fetch()], JSON_THROW_ON_ERROR));
    }

    public function power(int $user, int $id, bool $latest = false): array
    {
        return $this->read($user, $id, 'power', $latest);
    }

    public function images(int $user, int $id): array
    {
        return $this->read($user, $id, 'images', false);
    }

    private function tasks(int $user, int $id, string $kind): array
    {
        return $kind === 'power' ? (new InstancePowerService())->runtime($user, $id, true) : (new InstanceReinstallService())->view($user, $id, true);
    }

    private function empty(string $kind): array
    {
        return $kind === 'power' ? ['state' => 'unknown', 'checked_at' => null] : ['state' => 'unavailable', ...(new ReinstallOptions())->projection(), 'checked_at' => null];
    }

    private function project(string $kind, array $v): array
    {
        if (! is_string($v['checked_at'] ?? null) || ! preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $v['checked_at'])) {
            throw new \RuntimeException('Invalid observation time');
        }
        if ($kind === 'power') {
            return ['state' => (new PowerState($v['state'] ?? ''))->state, 'checked_at' => $v['checked_at']];
        }
        $options = new ReinstallOptions($v['images'] ?? [], $v['password_mode'] ?? '', $v['data_disk'] ?? '');

        return ['state' => $options->images ? 'available' : 'unsupported', ...$options->projection(), 'checked_at' => $v['checked_at']];
    }

    private function read(int $user, int $id, string $kind, bool $latest): array
    {
        $gate = $this->tasks($user, $id, $kind);
        $i = $this->instance($user, $id);
        $empty = $this->empty($kind);
        if ($gate['blocked'] || ! InstancePowerService::eligible($i)) {
            return $empty + ['cached' => false] + $gate;
        }
        try {
            [$driver,$credentials] = (new ProviderService())->resolve((int) $i['provider_id']);
            $scope = $this->scope($i, $credentials, $kind);
            $cache = new ReadObservationCache();
            if ($kind === 'power' && ! $latest) {
                $entry = $cache->read($scope);
                $v = $entry ? $this->project($kind, $entry['value']) : $empty;
                $cached = $entry !== null;
            } else {
                $cached = ! $latest && $cache->read($scope) !== null;
                $v = $cache->remember($scope, $kind === 'power' ? 120 : 300, function () use ($driver, $credentials, $i, $kind): array {
                    if ($kind === 'power') {
                        $state = $driver instanceof PowerObservationDriverInterface ? $driver->observePower(InstancePowerService::context($i), $credentials) : new PowerState('unsupported');
                        // Failed latest reads must not replace the last successful observation.
                        if ($state->state === 'unknown') {
                            throw new \RuntimeException('Power observation unknown');
                        }

                        return ['state' => $state->state, 'checked_at' => gmdate('Y-m-d H:i:s')];
                    }
                    $options = $driver instanceof ReinstallDriverInterface ? $driver->inspectReinstall(InstancePowerService::context($i), $credentials) : new ReinstallOptions();

                    return ['state' => $options->images ? 'available' : 'unsupported', ...$options->projection(), 'checked_at' => gmdate('Y-m-d H:i:s')];
                }, $latest);
                $v = $this->project($kind, $v);
            }
            // Do not hold MySQL locks over network I/O. Recheck ownership, binding, provider and gates afterward.
            $gate = $this->tasks($user, $id, $kind);
            $current = $this->instance($user, $id);
            [, $now] = (new ProviderService())->resolve((int) $current['provider_id']);
            if ($gate['blocked'] || ! hash_equals($scope, $this->scope($current, $now, $kind))) {
                return $empty + ['cached' => false] + $gate;
            }

            return $v + ['cached' => $cached] + $gate;
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable) {
            return $empty + ['cached' => false] + $this->tasks($user, $id, $kind);
        }
    }
}
