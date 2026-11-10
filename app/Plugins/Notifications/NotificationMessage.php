<?php

declare(strict_types=1);

namespace App\Plugins\Notifications;

final readonly class NotificationMessage
{
    public function __construct(public string $fingerprint, public string $subject, public string $text) {}
}
