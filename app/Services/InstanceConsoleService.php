<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, RedisStore, Security};
use App\Plugins\Providers\ConsoleDriverInterface;
use App\ConsoleRelay\TargetPolicy;
use PDO;

final class InstanceConsoleService
{
    public function principal(string $token): int
    {
        $p=Security::verifyToken($token);
        if (!$p || ($p['sub_type']??'user')!=='user' || !empty($p['is_impersonated'])) {
            throw new ApiException('Console requires an owner session',401,401);
        }
        $s=Database::getInstance()->prepare('SELECT status FROM users WHERE id=?');$s->execute([$p['uid']]);
        if ($s->fetchColumn()!=='active') { throw new ApiException('Account unavailable',403,403); }
        return $p['uid'];
    }

    public function eligible(int $user, int $id): array
    {
        return Database::transaction(function(PDO $db) use($user,$id): array {
            $s=$db->prepare('SELECT * FROM instances WHERE id=? AND user_id=? FOR UPDATE');$s->execute([$id,$user]);
            $i=$s->fetch() ?: throw new ApiException('Instance not found',404,404);
            if (!InstancePowerService::eligible($i)) { throw new ApiException('Console unavailable for this instance',409,409); }
            TaskGate::assertClear($db,$id);
            $s=$db->prepare("SELECT id FROM tasks WHERE instance_id=? AND status='pending' LIMIT 1");$s->execute([$id]);
            if ($s->fetch()) { throw new ApiException('Instance operation queued',409,409); }
            $s=$db->prepare('SELECT status FROM providers WHERE id=?');$s->execute([$i['provider_id']]);
            if ((int)$s->fetchColumn()!==1) { throw new ApiException('Provider unavailable',409,409); }
            return $i;
        });
    }

    public function binding(array $i): string
    {
        return Security::requestFingerprint(json_encode([$i['user_id'],InstancePowerService::context($i),$i['status'],$i['due_date'],$i['config_snapshot']],JSON_THROW_ON_ERROR));
    }

    public function issue(string $token, int $id): array
    {
        if (!config('console.origins') || !config('console.targets')) { throw new ApiException('Console is not configured',503,503); }
        $user=$this->principal($token);$i=$this->eligible($user,$id);
        $redis=new RedisStore();
        if (!$redis->command('SET','console:rate:'.$user.':'.$id,'1','NX','EX','5')) { throw new ApiException('Please wait before reconnecting',429,429); }
        try {
            [$d,$c]=(new ProviderService())->resolve((int)$i['provider_id']);
            if (!$d instanceof ConsoleDriverInterface) { throw new \RuntimeException(); }
            $target=$d->openConsole(InstancePowerService::context($i),$c);
            TargetPolicy::validate($target->url);
        } catch (\Throwable) { throw new ApiException('控制台暂不可用，请稍后重试或联系客服',409,409); }
        $fresh=$this->eligible($this->principal($token),$id);
        if (!hash_equals($this->binding($i),$this->binding($fresh))) { throw new ApiException('Instance changed; refresh first',409,409); }
        $ticket=bin2hex(random_bytes(32));
        $record=['user'=>$user,'instance'=>$id,'session'=>Security::requestFingerprint($token),'binding'=>$this->binding($i),
            'expires'=>time()+60,'url'=>$target->url,'password'=>$target->password,'origin'=>$target->origin];
        if (!$redis->command('SET','console:ticket:'.hash('sha256',$ticket),Security::encrypt(json_encode($record,JSON_THROW_ON_ERROR)),'NX','EX','60')) {
            throw new ApiException('Console authorization unavailable',503,503);
        }
        return ['path'=>'/console/ws/'.$ticket,'expires_in'=>60,'max_duration'=>900];
    }

    public function consume(string $token, string $ticket, string $origin): array
    {
        if (!in_array($origin,config('console.origins'),true) || !preg_match('/^[a-f0-9]{64}$/D',$ticket)) { throw new ApiException('Console rejected',403,403); }
        $user=$this->principal($token);
        $raw=(new RedisStore())->command('GETDEL','console:ticket:'.hash('sha256',$ticket));
        $r=is_string($raw)?json_decode(Security::decrypt($raw)??'null',true):null;
        if (!$r || $r['expires']<=time() || $r['user']!==$user || !hash_equals($r['session'],Security::requestFingerprint($token))) {
            throw new ApiException('Console authorization expired or used',403,403);
        }
        $this->revalidate($token,$r);
        return $r;
    }

    public function revalidate(string $token, array $r): void
    {
        $i=$this->eligible($this->principal($token),(int)$r['instance']);
        if (!hash_equals($r['binding'],$this->binding($i))) { throw new ApiException('Instance changed',409,409); }
    }
}
