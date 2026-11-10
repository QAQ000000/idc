<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use PDO;

final class TaskGate
{
    /** Caller must hold the instance row first. These are CURRENT reads, including expired blockers. */
    public static function assertClear(PDO $db, int $instanceId, int $ownTask = 0): void
    {
        $s = $db->prepare('SELECT id, status, last_error, action FROM tasks WHERE instance_id = ? ORDER BY id FOR UPDATE');
        $s->execute([$instanceId]);
        foreach ($s->fetchAll() as $t) {
            if ((int) $t['id'] !== $ownTask && (in_array($t['status'], ['running', 'investigating'], true)
                || ($t['status'] === 'pending' && in_array($t['action'], ['power', 'reinstall'], true))
                || ($t['status'] === 'failed' && str_starts_with($t['last_error'] ?? '', 'MANUAL_AUDIT_REQUIRED:')))) {
                throw new ApiException('Instance has an unresolved operation', 409, 409);
            }
        }
        $s = $db->prepare('SELECT id, status FROM invoices WHERE instance_id = ? ORDER BY id FOR UPDATE');
        $s->execute([$instanceId]);
        foreach ($s->fetchAll() as $i) {
            if ($i['status'] === 'refund_pending') {
                throw new ApiException('Instance refund in progress', 409, 409);
            }
            $r = $db->prepare('SELECT refund_status, payload FROM payment_records WHERE invoice_id = ? ORDER BY id FOR UPDATE');
            $r->execute([$i['id']]);
            foreach ($r->fetchAll() as $p) {
                $context = json_decode($p['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                if (($context['settlement_purpose'] ?? 'cloud_consumption') === 'cloud_consumption'
                    && ($p['refund_status'] === 'pending' || ($context['awaiting_termination'] ?? false))) {
                    throw new ApiException('Instance refund unresolved', 409, 409);
                }
            }
        }
    }
}
