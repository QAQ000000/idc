<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

interface UpstreamDriverInterface
{
    public function getDriverId(): string;

    public function getQueryAuthority(): string;

    public function getFormSchema(int $remotePid, array $credentials): array;

    public function preflightPrice(int $remotePid, string $billingCycle, array $selectedConfig, array $credentials): PreflightResult;

    /** create is called once per attempt; a timeout MUST NOT trigger a second create. */
    public function create(array $orderData, array $credentials): DriverExecutionResult;

    /** Read-only investigation using the original task identity and known remote operation id. */
    public function queryOrder(array $orderData, array $credentials): DriverExecutionResult;
}
