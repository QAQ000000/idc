<?php
declare(strict_types=1);
namespace App\Plugins\Providers;
use App\Plugins\PluginLoader;
final class ProviderRegistry
{
    public function __construct(private readonly array $factories) {}
    public static function installed(): self { return new self(PluginLoader::load(dirname(__DIR__, 3) . '/plugins/providers', 'provider', UpstreamDriverInterface::class)); }
    public function get(string $id): UpstreamDriverInterface
    {
        $driver = isset($this->factories[$id]) ? ($this->factories[$id])() : null;
        if (!$driver instanceof UpstreamDriverInterface || $driver->getDriverId() !== $id
            || !in_array($driver->getQueryAuthority(), ['AUTHORITATIVE_REALTIME', 'EVENTUAL_CONSISTENCY', 'NON_QUERYABLE'], true)) {
            throw new \RuntimeException('Provider driver unavailable');
        }
        return $driver;
    }
}
