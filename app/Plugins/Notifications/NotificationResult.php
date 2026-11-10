<?php

declare(strict_types=1);

namespace App\Plugins\Notifications;

final readonly class NotificationResult
{
    public function __construct(public string $status, public string $code)
    {
        if (! in_array($status, ['accepted', 'rejected', 'unknown'], true) || ! preg_match('/^[A-Z][A-Z0-9_]{0,99}$/D', $code)) {
            throw new \InvalidArgumentException('Invalid notification result');
        }
    }
}
