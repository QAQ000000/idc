<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

/** Optional, server-only console capability. Never serialize the returned target to a client. */
interface ConsoleDriverInterface
{
    public function openConsole(array $instance, array $credentials): ConsoleTarget;
}
