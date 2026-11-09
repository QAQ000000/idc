<?php
declare(strict_types=1);
namespace App\Plugins\Payments;
interface PaymentGatewayInterface
{
    public function getDriverId(): string;
    /** Pure local browser POST form descriptor; no HTTP/DB side effects, never contains the merchant secret. */
    public function checkout(array $payment, array $config): array;
    /** Reject invalid/unsigned/nonfinal notifications. No database side effects. */
    public function verify(array $notification, array $config): PaymentNotification;
}
