<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database, Money, Request};
use App\Plugins\Providers\{ConfigurableDriverInterface, ConfigOptions, SnapshotRulesDriverInterface, ProviderRegistry};
use PDO;

/** One schema import implementation shared by trusted CLI and the authorized admin service. */
final class ProductSnapshotService
{
    public function save(array $input): array
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('CLI only');
        }
        $prepared = $this->prepare($input);

        return Database::transaction(fn (PDO $db): array => $this->persist($db, $prepared));
    }

    /** Read-only upstream calls, never inside a database transaction. No credentials in the result. */
    public function prepare(array $input, bool $completeRules = false): array
    {
        $providerId = Request::id($input['provider_id'] ?? null);
        $remotePid = Request::id($input['remote_pid'] ?? null);
        $name = trim(Request::text($input, 'name', 1, 100));
        $type = Request::text($input, 'type', 1, 20);
        if ($name === '' || ! in_array($type, ['cloud', 'dedicated', 'cdn', 'hosting', 'software'], true)) {
            throw new ApiException('Invalid product name or type');
        }
        $markupType = $input['markup_type'] ?? 'none';
        $markupValue = $input['markup_value'] ?? '0.00';
        if (! is_string($markupType) || ! is_string($markupValue)) {
            throw new ApiException('Invalid markup');
        }
        Money::markup('1.00', $markupType, $markupValue);
        $markupValue = $markupType === 'none' ? '0.00' : bcadd($markupValue, '0', 2);
        $cycles = $input['billing_cycles'] ?? ['monthly'];
        if (! is_array($cycles) || ! array_is_list($cycles) || ! $cycles) {
            throw new ApiException('Invalid billing cycles');
        }
        foreach ($cycles as $cycle) {
            if (! is_string($cycle) || ! in_array($cycle, ['monthly', 'annually'], true)) {
                throw new ApiException('Invalid billing cycles');
            }
        }
        if (count(array_unique($cycles)) !== count($cycles)) {
            throw new ApiException('Duplicate billing cycles');
        }
        sort($cycles);
        try {
            [$driver,$credentials] = (new ProviderService())->resolve($providerId);
            if (! $driver instanceof ConfigurableDriverInterface) {
                throw new ApiException('Driver does not support schema snapshots', 409, 409);
            }
            $schema = $driver->getFormSchema($remotePid, $credentials);
            try {
                $form = $driver->describeForm($schema);
            } catch (\Throwable) {
                // Projection is pure: an unsupported product is distinct from transport/auth failure.
                throw new ApiException('Product configuration is not supported; no snapshot saved', 409, 40914);
            }
            $defaults = ConfigOptions::defaults($form);
            $config = ConfigOptions::validate($form, $input['config_options'] ?? $defaults);
            $prices = [];
            $recurring = [];
            $costs = [];
            foreach ($cycles as $cycle) {
                $quote = $driver->preflightPrice($remotePid, $cycle, $config, $credentials);
                (new StockObservationService())->remember($providerId, $remotePid, $quote->stock);
                if ($quote->currency !== 'CNY' || $quote->config !== $config || ($form && ($quote->context['schema_hash'] ?? '') !== ConfigOptions::hash($form))) {
                    throw new ApiException('Schema changed during import', 409, 409);
                }
                $costs[$cycle] = ['first' => Money::positive($quote->amount), 'recurring' => Money::positive($quote->recurringAmount ?? $quote->amount)];
                $prices[$cycle] = Money::markup($costs[$cycle]['first'], $markupType, $markupValue);
                $recurring[$cycle] = Money::markup($costs[$cycle]['recurring'], $markupType, $markupValue);
            }
            if ($completeRules) {
                if (! $driver instanceof SnapshotRulesDriverInterface) {
                    throw new \RuntimeException('Complete snapshot rules unsupported');
                }
                $driver->validateSnapshotRules($schema, $config, $costs);
                // A second content read detects changes during the bounded online comparisons.
                $again = $driver->getFormSchema($remotePid, $credentials);
                if (self::canonical($driver->snapshotContent($schema)) !== self::canonical($driver->snapshotContent($again))) {
                    throw new \RuntimeException('Snapshot rules changed during refresh');
                }
            }
            if ($driver instanceof SnapshotRulesDriverInterface) {
                $schema = $driver->snapshotContent($schema);
            }
        } catch (\App\Plugins\Providers\UpstreamOutOfStock) {
            throw new ApiException('Upstream product sold out; no snapshot saved', 409, 40914);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ApiException('Upstream schema or quote unavailable; no snapshot saved', 409, 409);
        }

        return ['product_id' => isset($input['product_id']) ? Request::id($input['product_id']) : null,
            'provider_id' => $providerId, 'remote_pid' => $remotePid, 'name' => $name, 'type' => $type, 'markup_type' => $markupType, 'markup_value' => $markupValue,
            'schema' => $schema, 'fields' => $form, 'config_options' => $config, 'prices' => $prices, 'recurring_prices' => $recurring, 'reference_costs' => $costs];
    }

    /** Internal write primitive; caller holds authorization/concurrency locks and owns the transaction. */
    public function persist(PDO $db, array $p, ?string $initialStatus = null): array
    {
        if (! $db->inTransaction()) {
            throw new \LogicException('Snapshot persistence requires transaction');
        }
        $id = $p['product_id'];
        $namespace = bin2hex(random_bytes(20));
        $oldCache = null;
        if ($id) {
            $s = $db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');
            $s->execute([$id]);
            $old = $s->fetch();
            if (! $old || (int) $old['provider_id'] !== $p['provider_id'] || (int) $old['remote_pid'] !== $p['remote_pid']) {
                throw new ApiException('Cannot change product resource ownership', 409, 409);
            }
            $oldCache = json_decode($old['config_cache'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            if (! is_string($oldCache['order_namespace'] ?? null) || $oldCache['order_namespace'] === '') {
                throw new ApiException('Missing order namespace; operator review required', 409, 409);
            }
            $namespace = $oldCache['order_namespace'];
        }
        $cache = ['configurable' => (bool) $p['fields'], 'prices' => $p['prices'], 'recurring_prices' => $p['recurring_prices'],
            'reference_costs' => $p['reference_costs'], 'default_config' => $p['config_options'], 'schema' => $p['schema'], 'order_namespace' => $namespace];
        if (! $p['fields']) {
            $cache['fixed_config'] = $p['config_options'];
        }
        if ($oldCache !== null) {
            $compare = $oldCache;
            unset($compare['snapshot_revision']);
            $s = $db->prepare('SELECT driver FROM providers WHERE id=?');
            $s->execute([$p['provider_id']]);
            $driver = ProviderRegistry::installed()->get($s->fetchColumn());
            if ($driver instanceof SnapshotRulesDriverInterface && is_array($compare['schema'] ?? null)) {
                $compare['schema'] = $driver->snapshotContent($compare['schema']);
            }
            if (self::canonical($cache) === self::canonical($compare)
                && $old['name'] === $p['name'] && $old['type'] === $p['type']
                && $old['markup_type'] === $p['markup_type'] && $old['markup_value'] === $p['markup_value']) {
                // No-content refresh must not invalidate existing confirmations or admin drafts.
                return ['product_id' => $id, 'prices' => $p['prices'], 'changed' => false];
            }
        }
        $revision = $oldCache['snapshot_revision'] ?? 0;
        if (! is_int($revision) || $revision < 0 || $revision >= PHP_INT_MAX) {
            throw new \RuntimeException('Invalid snapshot revision');
        }
        $cache['snapshot_revision'] = $revision + 1;
        $encoded = json_encode($cache, JSON_THROW_ON_ERROR);
        if ($id) {
            $db->prepare('UPDATE products SET name=?,type=?,markup_type=?,markup_value=?,config_cache=? WHERE id=?')->execute([$p['name'], $p['type'], $p['markup_type'], $p['markup_value'], $encoded, $id]);
        } else {
            $db->prepare('INSERT INTO products(provider_id,remote_pid,name,type,markup_type,markup_value,config_cache,is_auto_setup,status) VALUES(?,?,?,?,?,?,?,1,?)')
                ->execute([$p['provider_id'], $p['remote_pid'], $p['name'], $p['type'], $p['markup_type'], $p['markup_value'], $encoded, $initialStatus ?? 'active']);
            $id = (int) $db->lastInsertId();
        }

        return ['product_id' => $id, 'prices' => $p['prices'], 'changed' => true];
    }

    /** MySQL JSON key ordering is not sale-content drift; list ordering remains significant. */
    public static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            foreach ($v as &$item) {
                $item = $sort($item);
            } unset($item);

            return $v;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }
}
