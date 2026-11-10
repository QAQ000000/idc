<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

/** Optional console capability. Return only the cloud WS URL and VNC connection password, never account/host credentials. */
interface ConsoleDriverInterface
{
    public function openConsole(array $instance, array $credentials): ConsoleTarget;
}
