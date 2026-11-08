<?php
declare(strict_types=1);
namespace App\Plugins\Notifications;

use App\Plugins\PluginLoader;

final class NotificationRegistry
{
    public function __construct(private readonly array $factories) {}
    public static function installed(): self
    {
        return new self(PluginLoader::load(dirname(__DIR__, 3).'/plugins/notifications', 'notification', NotificationChannelInterface::class));
    }
    public function get(string $id): NotificationChannelInterface
    {
        $driver = isset($this->factories[$id]) ? ($this->factories[$id])() : null;
        if (!$driver instanceof NotificationChannelInterface || $driver->getChannelId() !== $id) {
            throw new \RuntimeException('Notification channel unavailable');
        }
        return $driver;
    }
}
