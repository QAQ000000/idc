<?php

declare(strict_types=1);

namespace Plugins\providers\zjmf;

use App\Core\Money;
use App\Plugins\Providers\ConfigOptions;

/** Diagnostic only: this result is not a checkout confirmation or a stock reservation. */
final class LocalPriceCalculator
{
    public const MAX_AGE = 900;

    /** Snapshot envelope is private, supplied by the read-only comparison caller, never persisted here. */
    public function evaluate(mixed $snapshot, string $cycle, array $options, int $now): array
    {
        try {
            if (! is_array($snapshot) || ! is_array($snapshot['schema'] ?? null)
                || ! is_int($snapshot['fetched_at'] ?? null)) {
                throw new \DomainException('SNAPSHOT_MISSING_OR_INVALID');
            }
            $age = $now - $snapshot['fetched_at'];
            if ($age < 0 || $age > self::MAX_AGE) {
                throw new \DomainException('SNAPSHOT_EXPIRED');
            }
            if (! in_array($cycle, ['monthly', 'annually'], true)) {
                throw new \DomainException('UNSUPPORTED_CYCLE');
            }
            $schema = $snapshot['schema'];
            $product = $schema['products'] ?? null;
            $currency = $snapshot['currency'] ?? null;
            if (! is_array($product) || ! is_array($currency) || ($currency['code'] ?? null) !== 'CNY'
                || ! is_int($currency['id'] ?? null) || $currency['id'] < 1) {
                throw new \DomainException('UNSUPPORTED_CURRENCY');
            }
            $currency = ['id' => $currency['id'], 'code' => 'CNY'];
            // No account discounts, tax, proration, postpayment, resource-grade or unknown price policy.
            if (($schema['flag'] ?? null) !== false || ($product['api_type'] ?? null) !== 'zjmf_api'
                || ($product['upstream_price_type'] ?? null) !== 'percent'
                || ($product['pay_method'] ?? null) !== 'prepayment') {
                throw new \DomainException('UNSUPPORTED_POLICY');
            }
            foreach (['tax', 'prorata_billing', 'prorata_charge_next_month'] as $key) {
                if (! in_array($product[$key] ?? null, [0, '0', '0.00'], true)) {
                    throw new \DomainException('UNSUPPORTED_POLICY');
                }
            }
            $pay = $product['pay_type'] ?? null;
            if (is_string($pay)) {
                $pay = json_decode($pay, true, 32, JSON_THROW_ON_ERROR);
            }
            if (! is_array($pay) || ($pay['pay_type'] ?? null) !== 'recurring') {
                throw new \DomainException('UNSUPPORTED_POLICY');
            }
            $multiplier = $this->decimal($product['upstream_price_value'] ?? null);
            if (bccomp($multiplier, '0', 2) <= 0 || bccomp($multiplier, '1000', 2) > 0) {
                throw new \DomainException('UNSUPPORTED_POLICY');
            }
            foreach (['customfields', 'advanced', 'config_groups', 'config_links'] as $key) {
                if (! isset($schema[$key]) || ! is_array($schema[$key]) || ! array_is_list($schema[$key])) {
                    throw new \DomainException('INVALID_MATRIX');
                }
            }
            $groups = [];
            foreach ($schema['config_groups'] as $group) {
                if (! is_array($group) || ! is_int($group['id'] ?? null) || $group['id'] < 1 || isset($groups[$group['id']])
                    || ! is_array($group['options'] ?? null) || ! array_is_list($group['options'])) {
                    throw new \DomainException('INVALID_MATRIX');
                }
                $groups[$group['id']] = true;
                foreach ($group['options'] as $option) {
                    if (! is_array($option) || ! is_int($option['id'] ?? null) || ! is_int($option['option_type'] ?? null) || ! is_string($option['option_name'] ?? null)
                        || ! is_array($option['sub'] ?? null) || ! array_is_list($option['sub']) || ! $option['sub']) {
                        throw new \DomainException('INVALID_MATRIX');
                    }
                    foreach ($option['sub'] as $sub) {
                        if (! is_array($sub) || ! is_int($sub['id'] ?? null) || ! is_string($sub['option_name'] ?? null)) {
                            throw new \DomainException('INVALID_MATRIX');
                        }
                    }
                }
            }
            $links = $schema['config_links'];
            foreach ($links as $link) {
                if (! is_int($link) || $link < 1) {
                    throw new \DomainException('INVALID_MATRIX');
                }
            }
            $groupIds = array_keys($groups);
            sort($groupIds, SORT_NUMERIC);
            sort($links, SORT_NUMERIC);
            if ($groupIds !== $links) {
                throw new \DomainException('INVALID_MATRIX');
            }
            $driver = new ZjmfDriver();
            $form = $driver->describeForm($schema);
            $options = ConfigOptions::validate($form, $options);
            $setupCycle = $cycle === 'monthly' ? 'msetupfee' : 'asetupfee';
            $base = $this->price($schema['product_pricings'] ?? null, $currency, $cycle, $setupCycle,
                'product', $product['id'] ?? null);
            $matrix = [];
            $contributions = [];
            foreach ($schema['config_groups'] as $group) {
                foreach ($group['options'] as $option) {
                    $id = (string) $option['id'];
                    if (isset($matrix[$id]) || ! empty($option['hidden'])) {
                        throw new \DomainException('INVALID_MATRIX');
                    }
                    $subs = [];
                    // Validate the entire requested-cycle matrix, including currently unselected choices.
                    foreach ($option['sub'] as $sub) {
                        $sid = (string) $sub['id'];
                        if (isset($subs[$sid]) || ! empty($sub['hidden'])) {
                            throw new \DomainException('INVALID_MATRIX');
                        }
                        $subs[$sid] = $this->price($sub['pricings'] ?? null, $currency, $cycle, $setupCycle,
                            'configoptions', $sub['id']);
                    }
                    ksort($subs, SORT_NUMERIC);
                    $matrix[$id] = $subs;
                    if (in_array((int) $option['option_type'], [15, 18], true)) {
                        $quantity = (int) $options[$id];
                        $price = 0;
                        $setup = '0.00';
                        $ranges = IntegerQuantity::project($option, '')['ranges'];
                        foreach ($ranges as $range) {
                            if ($quantity < $range['minimum']) {
                                break;
                            }
                            // Mirrors quantityStagePrice: min=0 does not add a fictitious zeroth unit.
                            $units = min($quantity, $range['maximum']) - max(1, $range['minimum']) + 1;
                            $price += (float) $subs[$range['id']]['price'] * max(0, $units);
                            if ($quantity <= $range['maximum']) {
                                $setup = $quantity === 0 ? '0.00' : $subs[$range['id']]['setup'];
                                break;
                            }
                        }
                        $contributions[] = [$price, $setup];
                    } else {
                        $selected = $subs[$options[$id]];
                        $contributions[] = [$selected['price'], $selected['setup']];
                    }
                }
            }
            // Deliberately preserve get_total's PHP numeric coercions and distinct BCMath stages.
            // Replacing these with one final round() changes cent-boundary results.
            $total = 0;
            $signal = 0;
            foreach ([[$base['price'], $base['setup']], ...$contributions] as [$price, $setup]) {
                $total += bcmul($setup, '1', 2);
                $total += bcmul((string) $price, '1', 2);
                $signal += $price;
            }
            $first = bcsub(bcmul((string) $total, (string) ($multiplier / 100), 2), '0', 2);
            $recurring = bcsub((string) (bcmul((string) $signal, $multiplier, 2) / 100), '0', 2);
            ksort($matrix, SORT_NUMERIC);
            // Diagnostic pricing identity only. Inventory and capture time are observations, not rules.
            $hash = hash('sha256', json_encode(['zjmf_get_total_php84_v1', $cycle, $currency, $multiplier, $form, $base, $matrix], JSON_THROW_ON_ERROR));

            return ['supported' => true, 'first' => Money::positive($first), 'recurring' => Money::positive($recurring),
                'currency' => 'CNY', 'config_options' => $options, 'pricing_hash' => $hash, 'schema_hash' => ConfigOptions::hash($form)];
        } catch (\DomainException $e) {
            return ['supported' => false, 'reason' => $e->getMessage()];
        } catch (\Throwable) {
            // No partial amount, zero-price default, cached combination or raw schema in a failure result.
            return ['supported' => false, 'reason' => 'INVALID_SNAPSHOT_OR_SELECTION'];
        }
    }

    private function decimal(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$/D', $value)) {
            throw new \DomainException('INVALID_MATRIX');
        }

        return bcadd($value, '0', 2);
    }

    private function price(mixed $rows, array $currency, string $cycle, string $setup, string $type, mixed $id): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== 1 || ! is_array($rows[0])) {
            throw new \DomainException('INVALID_MATRIX');
        }
        $row = $rows[0];
        if ((! is_int($row['currency'] ?? null) && ! is_string($row['currency'] ?? null))
            || ($row['code'] ?? null) !== $currency['code'] || (string) $row['currency'] !== (string) $currency['id']
            || ($row['type'] ?? null) !== $type || ! is_int($id) || $id < 1 || ($row['relid'] ?? null) !== $id) {
            throw new \DomainException('INVALID_MATRIX');
        }

        return ['price' => $this->decimal($row[$cycle] ?? null), 'setup' => $this->decimal($row[$setup] ?? null)];
    }
}
