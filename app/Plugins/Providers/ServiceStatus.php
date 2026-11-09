<?php
declare(strict_types=1);
namespace App\Plugins\Providers;

final readonly class ServiceStatus
{
    public function __construct(public string $state,public string $suspension='unknown',public ?string $dueDate=null,public string $code='SERVICE_QUERY_UNAVAILABLE')
    {
        if(!in_array($state,['active','suspended','terminated','cancelled','pending','unknown'],true)
            || !in_array($suspension,['billing','nonbilling','unknown'],true)
            || !preg_match('/^[A-Z0-9_]{1,80}$/D',$code)
            || ($dueDate!==null && (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$dueDate)
                || ($d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$dueDate,new \DateTimeZone('UTC')))===false || $d->format('Y-m-d H:i:s')!==$dueDate))){
            throw new \InvalidArgumentException('Invalid service status projection');
        }
    }
}
