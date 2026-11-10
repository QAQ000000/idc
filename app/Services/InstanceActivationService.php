<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\{ApiException, Database};
use PDO;

/** Confirmed delivery only; Worker completion remains fenced by a valid lease. */
final class InstanceActivationService
{
    public function completeCreate(int $taskId, string $workerId, int $executionToken, string $remoteId, ?string $ip): void
    {
        if ($remoteId === '' || strlen($remoteId) > 100 || ($ip !== null && ! filter_var($ip, FILTER_VALIDATE_IP))) {
            throw new ApiException('Invalid delivery result');
        }
        Database::transaction(static function (PDO $db) use ($taskId, $workerId, $executionToken, $remoteId, $ip): void {
            $stmt = $db->prepare('SELECT instance_id FROM tasks WHERE id = ?');
            $stmt->execute([$taskId]);
            $instanceId = $stmt->fetchColumn();
            if (! $instanceId) {
                throw new ApiException('Task not found', 404, 404);
            }
            // 与付款/销毁保持相同顺序：先实例，再任务与账单。
            $stmt = $db->prepare('SELECT * FROM instances WHERE id = ? FOR UPDATE');
            $stmt->execute([$instanceId]);
            $instance = $stmt->fetch();
            TaskGate::assertClear($db, (int) $instanceId, $taskId);
            $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? FOR UPDATE');
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();
            if (! $instance || $task['action'] !== 'create' || (int) $task['instance_id'] !== (int) $instanceId
                || $instance['status'] !== 'pending' || $instance['remote_instance_id'] !== null) {
                throw new ApiException('Instance cannot be activated', 409, 409);
            }
            $stmt = $db->prepare('SELECT status FROM invoices WHERE id = ? AND instance_id = ? AND user_id = ? AND type = \'new\' FOR UPDATE');
            $stmt->execute([$task['invoice_id'], $instanceId, $instance['user_id']]);
            if ($stmt->fetchColumn() !== 'paid') {
                throw new ApiException('Delivery requires a paid purchase', 409, 409);
            }
            $stmt = $db->prepare("UPDATE tasks SET status = 'success', lease_until = NULL WHERE id = ? AND worker_id = ? AND execution_token = ? AND status IN ('running', 'investigating') AND lease_until > UTC_TIMESTAMP()");
            $stmt->execute([$taskId, $workerId, $executionToken]);
            if ($stmt->rowCount() !== 1) {
                throw new ApiException('Task lease or execution token is no longer valid', 409, 409);
            }
            self::applyDelivery($db, $instance, $remoteId, $ip);
        });
    }

    /** Internal primitive; caller holds instance/task locks and has verified delivery authorization. */
    public static function applyDelivery(PDO $db, array $instance, string $remoteId, ?string $ip): void
    {
        if (! $db->inTransaction() || $remoteId === '' || strlen($remoteId) > 100 || trim($remoteId) !== $remoteId
            || ($ip !== null && ! filter_var($ip, FILTER_VALIDATE_IP))) {
            throw new ApiException('Invalid delivery result');
        }
        // MySQL calendar intervals clamp month ends (Jan 31 -> Feb 28).
        $interval = match ($instance['billing_cycle']) {
            'monthly' => '1 MONTH', 'annually' => '1 YEAR', default => throw new ApiException('Unsupported billing cycle')
        };
        $db->prepare("UPDATE instances SET status = 'active', remote_instance_id = ?, ip_address = ?, start_date = UTC_TIMESTAMP(), due_date = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$interval}) WHERE id = ?")
            ->execute([$remoteId, $ip, $instance['id']]);
    }
}
