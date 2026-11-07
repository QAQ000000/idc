<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

/** Optional adapter from a stored upstream JSON snapshot to a public, secret-free form. */
interface ConfigurableDriverInterface
{
    public function describeForm(array $snapshot): array;
}
