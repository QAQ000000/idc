<?php
declare(strict_types=1);
namespace App\Plugins\Providers;
final readonly class DriverExecutionResult
{
    public function __construct(
        public string $status,
        public ?string $remoteInstanceId = null,
        public ?string $ipAddress = null,
        public ?string $operationId = null,
        public string $code = 'UNCONFIRMED',
        public ?string $username = null,
        public ?string $password = null,
    ) {
        if (($username !== null && strlen($username) > 255) || ($password !== null && strlen($password) > 128)) { throw new \InvalidArgumentException('Invalid credentials'); }
        if (!in_array($status, ['succeeded', 'pending', 'unknown', 'not_executed', 'rejected'], true)
            || !preg_match('/^[A-Z0-9_]{1,80}$/D', $code)
            || ($operationId !== null && (strlen($operationId) > 100 || $operationId === ''))) {
            throw new \InvalidArgumentException('Invalid execution result');
        }
    }
}
