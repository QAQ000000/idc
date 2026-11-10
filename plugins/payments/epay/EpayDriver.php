<?php

declare(strict_types=1);

namespace Plugins\payments\epay;

use App\Core\{ApiException, Money};
use App\Plugins\Payments\{PaymentGatewayInterface, PaymentNotification};

final class EpayDriver implements PaymentGatewayInterface
{
    public function getDriverId(): string
    {
        return 'epay';
    }

    public static function sign(array $fields, string $secret): string
    {
        unset($fields['sign'], $fields['sign_type']);
        ksort($fields, SORT_STRING);
        $parts = [];
        foreach ($fields as $k => $v) {
            if (! is_scalar($v)) {
                throw new ApiException('Invalid notification');
            }
            if ((string) $v !== '') {
                $parts[] = $k.'='.$v;
            }
        }

        return md5(implode('&', $parts).$secret);
    }

    public function checkout(array $payment, array $config): array
    {
        $type = $config['type'] ?? 'alipay';
        if (! in_array($type, ['alipay', 'wxpay', 'qqpay'], true)) {
            throw new ApiException('Invalid payment type');
        }
        $fields = ['type' => $type, 'pid' => $config['pid'], 'out_trade_no' => $payment['trade_no'], 'name' => '简云账单 '.$payment['invoice_id'],
            'money' => $payment['amount'], 'notify_url' => $config['notify_url'], 'return_url' => rtrim($config['return_url'], '/').'/invoices/'.$payment['invoice_id']];
        $fields['sign'] = self::sign($fields, $config['key']);
        $fields['sign_type'] = 'MD5';

        return ['method' => 'POST', 'action' => rtrim($config['endpoint'], '/').'/submit.php', 'fields' => $fields];
    }

    public function verify(array $notification, array $config): PaymentNotification
    {
        foreach ($notification as $k => $v) {
            if (! is_string($k) || ! is_scalar($v) || strlen((string) $v) > 2048) {
                throw new ApiException('Invalid notification');
            }
        }
        if (($notification['sign_type'] ?? 'MD5') !== 'MD5' || ! is_string($notification['sign'] ?? null)
            || ! preg_match('/^[a-f0-9]{32}$/D', $notification['sign'])
            || ! hash_equals(self::sign($notification, $config['key']), $notification['sign'])
            || (string) ($notification['pid'] ?? '') !== (string) $config['pid']
            || ($notification['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            throw new ApiException('Unverified payment notification');
        }
        $order = $notification['out_trade_no'] ?? '';
        $trade = $notification['trade_no'] ?? '';
        if (! is_string($order) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $order)
            || ! is_string($trade) || ! preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $trade)) {
            throw new ApiException('Invalid payment identity');
        }

        return new PaymentNotification($order, $trade, Money::positive($notification['money'] ?? null));
    }
}
