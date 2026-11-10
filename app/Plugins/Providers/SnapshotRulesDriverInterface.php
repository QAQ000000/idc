<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

/** Optional complete-rule publication capability. Provider-specific semantics stay in the plugin. */
interface SnapshotRulesDriverInterface
{
    /** Remove observation fields without discarding sale/configuration/price/input rules. */
    public function snapshotContent(array $schema): array;

    /** Reject incomplete rules or any mismatch against the authoritative online cycle quotes. */
    public function validateSnapshotRules(array $schema, array $options, array $quotes): void;
}
