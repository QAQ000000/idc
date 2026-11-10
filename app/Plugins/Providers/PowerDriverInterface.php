<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

/** Optional, account-scoped capability. No raw module response may cross this boundary. */
interface PowerDriverInterface
{
    public function inspectPower(array $instance, array $credentials): PowerState;

    /** A positive response proves command acceptance, not a completed reboot or guest health. */
    public function executePower(array $operation, array $credentials): DriverExecutionResult;

    /** Read-only investigation; current power state alone never proves a reboot executed. */
    public function queryPower(array $operation, array $credentials): DriverExecutionResult;
}
