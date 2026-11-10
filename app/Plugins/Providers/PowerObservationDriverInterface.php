<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

/** Read-only observation of the original binding. No capabilities or operation authority. */
interface PowerObservationDriverInterface
{
    public function observePower(array $instance, array $credentials): PowerState;
}
