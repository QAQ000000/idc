<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

/** Optional capability; installing a create-only driver never enables renewal. */
interface RenewalDriverInterface
{
    /** Account-scoped, read-only quote. Context must contain no credentials. */
    public function preflightRenewal(array $instance, array $credentials): PreflightResult;

    public function renew(array $order, array $credentials): DriverExecutionResult;

    /** Never repeats renewal submission, payment or unsuspension. */
    public function queryRenewal(array $order, array $credentials): DriverExecutionResult;
}
