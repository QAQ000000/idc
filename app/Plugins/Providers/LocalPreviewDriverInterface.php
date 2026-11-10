<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

/** Pure local calculation only; no network, credentials or database access. */
interface LocalPreviewDriverInterface
{
    public function previewPrice(array $schema, string $cycle, array $options): ?PreflightResult;
}
