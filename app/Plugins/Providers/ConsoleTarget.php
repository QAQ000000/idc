<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

final readonly class ConsoleTarget
{
    public function __construct(public string $url, public string $password) {}
}
