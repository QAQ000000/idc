<?php
declare(strict_types=1);
namespace Plugins\providers\manual;
use App\Plugins\Providers\{UpstreamDriverInterface, DriverExecutionResult, PreflightResult};
final class ManualDriver implements UpstreamDriverInterface
{
    public function getDriverId(): string { return 'manual'; }
    public function getQueryAuthority(): string { return 'NON_QUERYABLE'; }
    public function getFormSchema(int $remotePid, array $credentials): array { return []; }
    public function preflightPrice(int $remotePid, string $billingCycle, array $selectedConfig, array $credentials): PreflightResult { throw new \LogicException('Manual plans use local fixed prices'); }
    public function create(array $orderData, array $credentials): DriverExecutionResult { return new DriverExecutionResult('unknown', code: 'NON_QUERYABLE'); }
    public function queryOrder(array $orderData, array $credentials): DriverExecutionResult { return new DriverExecutionResult('unknown', code: 'NON_QUERYABLE'); }
}
