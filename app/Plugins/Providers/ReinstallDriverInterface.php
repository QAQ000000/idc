<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

/** Optional destructive operation; no fallback to create or power. */
interface ReinstallDriverInterface
{
    public function inspectReinstall(array $instance, array $credentials): ReinstallOptions;
    /** Acceptance is pending; succeeded requires operation-correlated completion and new credentials. */
    public function executeReinstall(array $operation, array $credentials): DriverExecutionResult;
    /** Read-only, including after a lost response or claim recovery. */
    public function queryReinstall(array $operation, array $credentials): DriverExecutionResult;
}
