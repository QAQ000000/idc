<?php

declare(strict_types=1);

namespace Plugins\providers\zjmf;

use App\Core\Money;
use App\Plugins\Providers\{ConfigOptions, PreflightResult};

/** Read-only shadow comparison; the online result remains authoritative in every case. */
final class PriceComparison
{
    public function compare(mixed $snapshot, string $cycle, array $options, int $now, callable $online): array
    {
        $local = (new LocalPriceCalculator())->evaluate($snapshot, $cycle, $options, $now);
        // Exactly one online attempt. An upstream failure propagates; never serve the local value instead.
        $quote = $online();
        if (! $quote instanceof PreflightResult || $quote->currency !== 'CNY' || $quote->recurringAmount === null
            || ConfigOptions::canonical($quote->config) !== ConfigOptions::canonical($options)) {
            throw new \RuntimeException('Online quote is not comparable');
        }
        $upstream = ['first' => Money::positive($quote->amount), 'recurring' => Money::positive($quote->recurringAmount), 'currency' => 'CNY'];
        $status = ! $local['supported'] ? 'online_only'
            : (($quote->context['schema_hash'] ?? null) !== $local['schema_hash'] ? 'rules_changed'
                : (($local['first'] === $upstream['first'] && $local['recurring'] === $upstream['recurring']) ? 'matched' : 'mismatch'));

        return ['status' => $status, 'local' => $local, 'upstream' => $upstream, 'authoritative' => 'upstream'];
    }
}
