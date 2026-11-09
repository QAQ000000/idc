<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use App\Plugins\Providers\{ReinstallDriverInterface, ReinstallOptions, DriverExecutionResult};
use PDO;

final class InstanceReinstallService
{
    private function owned(int $user,int $id,bool $lock=false): array
    {
        $s=Database::getInstance()->prepare('SELECT * FROM instances WHERE id=? AND user_id=?'.($lock?' FOR UPDATE':''));$s->execute([$id,$user]);
        return $s->fetch()?:throw new ApiException('Instance not found',404,404);
    }
    private function version(array $i): string
    {
        $s=Database::getInstance()->prepare('SELECT COALESCE(MAX(id),0) FROM tasks WHERE instance_id=?');$s->execute([$i['id']]);
        return Security::requestFingerprint(json_encode([$i['id'],$i['user_id'],InstancePowerService::context($i),$i['status'],$i['due_date'],$i['config_snapshot'],(int)$s->fetchColumn()],JSON_THROW_ON_ERROR));
    }
    private static function clear(PDO $db,int $id): void
    {
        TaskGate::assertClear($db,$id);
        $s=$db->prepare("SELECT id FROM tasks WHERE instance_id=? AND status='pending' FOR UPDATE");$s->execute([$id]);
        if($s->fetch()){throw new ApiException('Instance has a queued operation',409,409);}
    }
    private function inspect(array $i): ReinstallOptions
    {
        if(!InstancePowerService::eligible($i)){return new ReinstallOptions();}
        [$driver,$credentials]=(new ProviderService())->resolve((int)$i['provider_id']);
        return $driver instanceof ReinstallDriverInterface ? $driver->inspectReinstall(InstancePowerService::context($i),$credentials) : new ReinstallOptions();
    }
    /** Local durable history is available even when the upstream is unavailable. */
    public function view(int $user,int $id,bool $tasksOnly=false): array
    {
        $i=$this->owned($user,$id);$version=$tasksOnly?'':$this->version($i);$state='available';$options=new ReinstallOptions();
        if(!$tasksOnly){
            try{$options=$this->inspect($i);if(!$options->images){$state='unsupported';}}
            catch(\Throwable){$state='unavailable';}
        }
        return Database::transaction(function(PDO $db)use($user,$id,$version,$options,$state,$tasksOnly):array{
            $i=$this->owned($user,$id,true);$blocked=false;
            try{self::clear($db,$id);}catch(ApiException $e){if($e->httpStatus!==409){throw $e;}$blocked=true;}
            $s=$db->prepare("SELECT id,status,last_error,payload,updated_at FROM tasks WHERE instance_id=? AND action='reinstall' ORDER BY id DESC LIMIT 10");$s->execute([$id]);$tasks=[];
            foreach($s->fetchAll() as $t){$p=json_decode($t['payload'],true,32,JSON_THROW_ON_ERROR);$tasks[]=['id'=>(int)$t['id'],'status'=>$t['status'],'image_name'=>$p['image_name']??'',
                'result'=>self::result($t,$p),
                'accepted'=>($p['reinstall_accepted']??false)===true,'updated_at'=>$t['updated_at']];}
            if($tasksOnly){return ['blocked'=>$blocked,'tasks'=>$tasks];}
            $current=$this->version($i);
            $available=InstancePowerService::eligible($i)&&!$blocked&&hash_equals($version,$current)&&$options->images;
            $claims=['user'=>$user,'instance'=>$id,'version'=>$current,'expires'=>time()+120,'options'=>$available?Security::requestFingerprint(json_encode($options->projection(),JSON_THROW_ON_ERROR)):null];
            $body=rtrim(strtr(base64_encode(json_encode($claims,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
            return ['state'=>hash_equals($version,$current)?$state:'unavailable','available'=>(bool)$available,'blocked'=>$blocked,
                ...$options->projection(),'operation_token'=>$body.'.'.Security::requestFingerprint('reinstall:'.$body),'tasks'=>$tasks];
        });
    }
    public static function result(array $task,array $payload): string
    {
        if ($task['status']==='failed') { return $task['last_error']==='ADMIN_VERIFIED:REINSTALL_NOT_EXECUTED'?'not_executed':'needs_review'; }
        if ($task['status']==='success') { return ($payload['reinstall_outcome']??null)==='execution_ended'?'execution_ended':'success'; }
        if ($task['status']==='investigating' && ($payload['reinstall_accepted']??false)===true) {
            return match($task['last_error']) {
                'REINSTALL_EXECUTING'=>'executing',
                'REINSTALL_ACCEPTED','REINSTALL_ACCEPTED_WAITING'=>'accepted',
                'REINSTALL_CREDENTIALS_PENDING'=>'awaiting_credentials',
                default=>'query_unavailable',
            };
        }
        return $task['status'];
    }
    public function submit(int $user,int $id,array $input): array
    {
        if(\App\Middlewares\AuthMiddleware::isImpersonated()){throw new ApiException('Reinstall requires owner session',403,403);}
        if(array_diff(array_keys($input),['image_id','request_key','operation_token','confirmed_system_disk_erase'])){throw new ApiException('Unsupported reinstall fields; password is generated upstream');}
        if(($input['confirmed_system_disk_erase']??false)!==true){throw new ApiException('Confirm backup and system disk erasure');}
        $image=Request::text($input,'image_id',1,10);$key=Request::text($input,'request_key',16,64);
        if(!preg_match('/^[a-zA-Z0-9_-]+$/D',$key)){throw new ApiException('Invalid request key');}
        $raw=Request::text($input,'operation_token',1,2000);$parts=explode('.',$raw);
        if(count($parts)!==2||!hash_equals(Security::requestFingerprint('reinstall:'.$parts[0]),$parts[1])){throw new ApiException('Invalid reinstall confirmation',409,409);}
        $claims=json_decode(base64_decode(strtr($parts[0],'-_','+/'),true)?:'{}',true,16,JSON_THROW_ON_ERROR);
        if(($claims['user']??0)!==$user||($claims['instance']??0)!==$id||($claims['expires']??0)<=time()||!is_string($claims['options']??null)){throw new ApiException('Reinstall confirmation expired or unavailable',409,409);}
        $i=$this->owned($user,$id);
        try{$options=$this->inspect($i);}catch(\Throwable){throw new ApiException('Reinstall options unavailable; refresh first',409,409);}
        $hash=Security::requestFingerprint(json_encode($options->projection(),JSON_THROW_ON_ERROR));
        if(!hash_equals($hash,$claims['options'])||!in_array($image,array_column($options->images,'id'),true)){throw new ApiException('Reinstall image or rules changed; refresh first',409,409);}
        return Database::transaction(function(PDO $db)use($user,$id,$claims,$options,$image,$key):array{
            $i=$this->owned($user,$id,true);
            if(($claims['expires']??0)<=time()||!InstancePowerService::eligible($i)||!hash_equals($this->version($i),$claims['version'])){throw new ApiException('Instance changed; refresh first',409,409);}
            self::clear($db,$id);
            CustomerAccessService::assertActive($db,$user);
            $s=$db->prepare('SELECT status FROM providers WHERE id=? FOR UPDATE');$s->execute([$i['provider_id']]);if((int)$s->fetchColumn()!==1){throw new ApiException('Provider unavailable',409,409);}
            $identity='instance_reinstall:'.$id.':'.$key;
            $s=$db->prepare('SELECT id FROM tasks WHERE idempotency_key=?');$s->execute([$identity]);if($s->fetch()){throw new ApiException('Reinstall already submitted',409,409);}
            $row=array_values(array_filter($options->images,fn($v)=>$v['id']===$image))[0];
            $p=InstancePowerService::context($i)+['reinstall_options'=>$options->projection(),'image_id'=>$image,'image_name'=>$row['name'],'requested_by'=>$user];
            $db->prepare("INSERT INTO tasks(instance_id,invoice_id,idempotency_key,action,payload,max_retries) VALUES(?,NULL,?,'reinstall',?,0)")->execute([$id,$identity,json_encode($p,JSON_THROW_ON_ERROR)]);
            return ['task_id'=>(int)$db->lastInsertId(),'status'=>'pending'];
        });
    }
    public static function assertCredentialsAvailable(PDO $db,int $id): void
    {
        $s=$db->prepare("SELECT id FROM tasks WHERE instance_id=? AND action='reinstall' AND (status IN ('pending','running','investigating') OR (status='failed' AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%')) LIMIT 1");$s->execute([$id]);
        if($s->fetch()){throw new ApiException('Reinstall outcome unresolved; credentials require verification',409,409);}
    }
    /** Caller holds instance/task locks and fencing checks. Never changes identity, prices or term. */
    public static function complete(PDO $db,array $task,DriverExecutionResult $result): void
    {
        $p=json_decode($task['payload'],true,32,JSON_THROW_ON_ERROR);
        $s=$db->prepare('SELECT * FROM instances WHERE id=?');$s->execute([$task['instance_id']]);$i=$s->fetch();
        if(!$i||(int)$i['user_id']!==($p['requested_by']??null)||!InstancePowerService::matchesContext($i,$p)||$result->remoteInstanceId!==$i['remote_instance_id']
            ||!is_string($result->username)||trim($result->username)===''||!is_string($result->password)||$result->password===''
            ||preg_match('/[\x00-\x1f\x7f]/',$result->username.$result->password)){throw new ApiException('Reinstall completion requires original resource and verified new credentials',409,409);}
        $snapshot=json_decode($i['config_snapshot'],true,64,JSON_THROW_ON_ERROR);
        $snapshot['delivery']['username']=$result->username;
        $ended=$result->code==='REINSTALL_EXECUTION_ENDED';
        if ($ended && (($p['reinstall_accepted']??false)!==true || ($p['reinstall_monitor_version']??null)!==1
            || ($p['reinstall_execution_observed']??false)!==true)) { throw new ApiException('Reinstall execution observation missing',409,409); }
        if ($ended) { unset($snapshot['delivery']['reinstalled_image']); }
        else { $snapshot['delivery']['reinstalled_image']=$p['image_name']; }
        $snapshot['delivery']['credentials_verification']=$ended?'upstream_reported':'verified';
        $p['reinstall_outcome']=$ended?'execution_ended':'verified';
        $db->prepare('UPDATE tasks SET payload=? WHERE id=?')->execute([json_encode($p,JSON_THROW_ON_ERROR),$task['id']]);
        $db->prepare('UPDATE instances SET config_snapshot=?,encrypted_password=? WHERE id=?')->execute([json_encode($snapshot,JSON_THROW_ON_ERROR),Security::encrypt($result->password),$i['id']]);
    }
}
