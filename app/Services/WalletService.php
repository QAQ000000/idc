<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException,Database,Money,Request,Security};
use PDO;

final class WalletService
{
    public function summary(int $user): array
    {
        $s = Database::getInstance()->prepare('SELECT balance FROM users WHERE id=?');
        $s->execute([$user]);
        $balance = $s->fetchColumn();
        if ($balance === false) {
            throw new ApiException('Account not found', 404, 404);
        }

        return ['balance' => $balance, 'currency' => 'CNY'];
    }

    public function recharge(int $user, array $input): array
    {
        $amount = Money::positive($input['amount'] ?? null);
        $key = Request::text($input, 'request_key', 16, 48);
        if (! preg_match('/^[a-zA-Z0-9_-]{16,48}$/D', $key)) {
            throw new ApiException('Invalid request_key');
        }
        $key = 'recharge:'.$key;
        $hash = Security::requestFingerprint(json_encode(['recharge', $amount], JSON_THROW_ON_ERROR));

        return Database::transaction(function (PDO $db) use ($user, $amount, $key, $hash): array {
            // Only the user and a new invoice are locked; replay never locks an old invoice behind the wallet.
            CustomerAccessService::assertActive($db, $user);
            $s = $db->prepare('SELECT id,checkout_hash FROM invoices WHERE user_id=? AND checkout_key=?');
            $s->execute([$user, $key]);
            if ($existing = $s->fetch()) {
                if (! hash_equals($existing['checkout_hash'], $hash)) {
                    throw new ApiException('Recharge key already used with a different amount', 409, 409);
                }

                return (new BillingService())->invoice($user, (int) $existing['id']);
            }
            $db->prepare("INSERT INTO invoices(user_id,type,total,checkout_key,checkout_hash) VALUES(?,'recharge',?,?,?)")->execute([$user, $amount, $key, $hash]);

            return (new BillingService())->invoice($user, (int) $db->lastInsertId());
        });
    }
}
