<?php
declare(strict_types=1);
namespace App\Plugins\Notifications;

interface NotificationChannelInterface
{
    public function getChannelId(): string;
    public function getName(): string;
    /** Laravel validation rules for private configuration; no executable configuration. */
    public function getConfigSchema(): array;
    /** One external send attempt; no transparent message retry or failover. */
    public function send(string $target, NotificationMessage $message, array $config): NotificationResult;
}
