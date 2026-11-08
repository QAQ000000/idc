<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

final readonly class PowerState
{
    public function __construct(public string $state, public array $actions = [])
    {
        if (!in_array($state, ['on','off','transitioning','unknown','unsupported'], true)
            || array_diff($actions, ['start','stop','reboot']) || !array_is_list($actions)) {
            throw new \InvalidArgumentException('Invalid power state');
        }
    }
}
