<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

/** Optional upstream-managed lifecycle. Read-only; never stop, release or unsuspend a resource. */
interface ServiceStatusDriverInterface
{
    public function inspectServiceStatus(array $instance,array $credentials): ServiceStatus;
}
