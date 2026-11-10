<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

final class UpstreamOutOfStock extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Upstream sold out');
    }
}
