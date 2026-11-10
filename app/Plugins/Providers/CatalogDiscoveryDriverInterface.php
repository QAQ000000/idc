<?php

declare(strict_types=1);

namespace App\Plugins\Providers;

interface CatalogDiscoveryDriverInterface
{
    /** @return UpstreamCatalogItem[] Read-only candidates, not validated saleable products. */
    public function discoverCatalog(array $credentials): array;
}
