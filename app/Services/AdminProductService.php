<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException,Database,Money,Request,Security};
use App\Plugins\Providers\{ConfigurableDriverInterface,ConfigOptions,ProviderRegistry};
use PDO;

final class AdminProductService
{
    public function listing(array $q): array
    {
        $page=Request::id($q['page']??'1');$size=Request::id($q['per_page']??'20');
        if ($page>100000 || $size>100) { throw new ApiException('Pagination limit exceeded'); }
        $where=[];$args=[];
        if (isset($q['status']) && $q['status']!=='') {
            if (!is_string($q['status']) || !in_array($q['status'],['active','hidden','retired'],true)) { throw new ApiException('Invalid status'); }
            $where[]='p.status=?';$args[]=$q['status'];
        }
        if (isset($q['provider_id']) && $q['provider_id']!=='') { $where[]='p.provider_id=?';$args[]=Request::id($q['provider_id']); }
        if (isset($q['name']) && $q['name']!=='') { $where[]="p.name LIKE ? ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],Request::text($q,'name',1,100)).'%'; }
        $sql=' FROM products p JOIN providers r ON r.id=p.provider_id'.($where?' WHERE '.implode(' AND ',$where):'');$db=Database::getInstance();
        $s=$db->prepare('SELECT COUNT(*)'.$sql);$s->execute($args);$total=(int)$s->fetchColumn();$offset=($page-1)*$size;
        $s=$db->prepare('SELECT p.*,r.name AS provider_name,r.driver,r.status AS provider_status'.$sql." ORDER BY p.id DESC LIMIT {$size} OFFSET {$offset}");$s->execute($args);
        return ['items'=>array_map(fn($p)=>$this->project($p),$s->fetchAll()),'total'=>$total,'page'=>$page,'per_page'=>$size];
    }
    public function providers(): array
    {
        $rows=Database::getInstance()->query('SELECT id,name,driver,status FROM providers ORDER BY id')->fetchAll();
        foreach ($rows as &$r) { try { $r['snapshot_supported']=ProviderRegistry::installed()->get($r['driver']) instanceof ConfigurableDriverInterface; } catch (\Throwable) { $r['snapshot_supported']=false; } }
        return $rows;
    }
    public function detail(int $id, array $query=[]): array
    {
        $p=$this->read($id);$result=$this->project($p);$cache=json_decode($p['config_cache']??'{}',true,64,JSON_THROW_ON_ERROR);
        $result['version']=$this->version($p);$result['fields']=[];$result['defaults']=$cache['default_config']??[];$result['configuration_error']=null;
        $result['snapshot_refresh']=(new ProductRefreshService())->observation($id,$cache);
        if ($cache['configurable']??false) {
            try { $driver=ProviderRegistry::installed()->get($p['driver']);$result['fields']=$driver instanceof ConfigurableDriverInterface?$driver->describeForm($cache['schema']):[]; }
            catch (\Throwable) { $result['configuration_error']='Stored schema cannot be rendered; refresh requires operator review'; }
        }
        $page=Request::id($query['audit_page']??'1');if($page>100000){throw new ApiException('Pagination limit exceeded');}
        $db=Database::getInstance();$s=$db->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='product' AND target_id=?");$s->execute([$id]);$total=(int)$s->fetchColumn();$offset=($page-1)*20;
        $s=$db->prepare("SELECT l.id,l.admin_id,a.username,l.action,l.context,l.created_at FROM admin_audit_logs l LEFT JOIN admins a ON a.id=l.admin_id WHERE l.target_type='product' AND l.target_id=? ORDER BY l.id DESC LIMIT 20 OFFSET {$offset}");$s->execute([$id]);$audit=[];
        foreach($s->fetchAll() as $row){$c=json_decode($row['context']??'{}',true,32,JSON_THROW_ON_ERROR);$row['context']=array_intersect_key($c,array_flip(['evidence','before','after','prices']));$audit[]=$row;}
        $result['audit']=['items'=>$audit,'total'=>$total,'page'=>$page,'per_page'=>20];return $result;
    }
    private function read(int $id): array
    {
        $s=Database::getInstance()->prepare('SELECT p.*,r.name AS provider_name,r.driver,r.status AS provider_status FROM products p JOIN providers r ON r.id=p.provider_id WHERE p.id=?');$s->execute([$id]);$p=$s->fetch();if(!$p){throw new ApiException('Product not found',404,404);}return $p;
    }
    private function project(array $p): array
    {
        $cache=json_decode($p['config_cache']??'{}',true,64,JSON_THROW_ON_ERROR);
        $out=array_intersect_key($p,array_flip(['id','provider_id','remote_pid','name','type','status','markup_type','markup_value','stock','provider_name','driver','provider_status','updated_at']));
        $out['prices']=$cache['prices']??[];$out['recurring_prices']=$cache['recurring_prices']??[];$out['billing_cycles']=array_keys($out['prices']);$out['configurable']=(bool)($cache['configurable']??false);
        try{$out['snapshot_supported']=(int)$p['remote_pid']>0 && ProviderRegistry::installed()->get($p['driver']) instanceof ConfigurableDriverInterface;}catch(\Throwable){$out['snapshot_supported']=false;}
        return $out;
    }
    private function version(array $p): string
    {
        $s=Database::getInstance()->prepare("SELECT COALESCE(MAX(id),0) FROM admin_audit_logs WHERE target_type='product' AND target_id=?");$s->execute([$p['id']]);
        return Security::requestFingerprint(json_encode([array_intersect_key($p,array_flip(['id','provider_id','remote_pid','name','type','status','markup_type','markup_value','config_cache','is_auto_setup','commission_rate'])),$s->fetchColumn()],JSON_THROW_ON_ERROR));
    }
    private function checkVersion(?array $p, array $input): void
    {
        if (!$p || !hash_equals($this->version($p),Request::text($input,'expected_version',64,64))) { throw new ApiException('Product changed; reload and review again',409,409); }
    }
    private function authorize(int $admin, ?PDO $db=null): void
    {
        $s=($db??Database::getInstance())->prepare("SELECT id FROM admins WHERE id=? AND status='active' AND role='super_admin'".($db?' FOR UPDATE':''));$s->execute([$admin]);if(!$s->fetchColumn()){throw new ApiException('Active super_admin required',403,403);}
    }
    private function evidence(array $input): string
    {
        $v=trim(Request::text($input,'evidence',10,1000));if(strlen($v)<10){throw new ApiException('Evidence requires 10–1000 bytes');}return $v;
    }
    public function update(int $admin,int $id,array $input): array
    {
        $this->authorize($admin);$old=$this->read($id);$this->checkVersion($old,$input);
        // Older clients may still send a note; routine edits no longer require one.
        $evidence=array_key_exists('evidence',$input)?trim(Request::text($input,'evidence',0,1000)):'';
        foreach(array_keys($input) as $key){if(!in_array($key,['name','status','markup_type','markup_value','expected_version','evidence'],true)){throw new ApiException('Unsupported product edit field');}}
        $name=trim(Request::text($input,'name',1,100));$status=Request::text($input,'status',1,20);$type=Request::text($input,'markup_type',1,20);$value=Request::text($input,'markup_value',1,20);
        if($name==='' || !in_array($status,['active','hidden','retired'],true)){throw new ApiException('Invalid name or sales status');}
        Money::markup('1.00',$type,$value);$value=$type==='none'?'0.00':bcadd($value,'0',2);
        $cache=json_decode($old['config_cache']??'{}',true,64,JSON_THROW_ON_ERROR);
        if($type!==$old['markup_type'] || bccomp($value,$old['markup_value'],2)!==0){
            $costs=$cache['reference_costs']??[];
            if(!$costs && $old['driver']==='manual' && $old['markup_type']==='none'){
                foreach($cache['prices']??[] as $cycle=>$price){$costs[$cycle]=['first'=>$price,'recurring'=>$price];}
            }
            if(!$costs && $old['driver']!=='manual'){
                try{
                    [$driver,$credentials]=(new ProviderService())->resolve((int)$old['provider_id']);
                    $options=$cache['default_config']??$cache['fixed_config']??[];
                    $form=($cache['configurable']??false) && $driver instanceof ConfigurableDriverInterface?$driver->describeForm($cache['schema']):[];
                    foreach(array_keys($cache['prices']??[]) as $cycle){$q=$driver->preflightPrice((int)$old['remote_pid'],$cycle,$options,$credentials);
                        if($q->currency!=='CNY' || $q->config!==$options || ($form && ($q->context['schema_hash']??'')!==ConfigOptions::hash($form))){throw new \RuntimeException('Reference mismatch');}
                        $costs[$cycle]=['first'=>Money::positive($q->amount),'recurring'=>Money::positive($q->recurringAmount??$q->amount)];
                    }
                }catch(\Throwable){throw new ApiException('Reference prices unavailable; preview and refresh snapshot first',409,409);}
            }
            if(!$costs){throw new ApiException('No reliable reference prices; cannot change markup',409,409);}
            foreach(array_keys($cache['prices']) as $cycle){$cache['prices'][$cycle]=Money::markup($costs[$cycle]['first'],$type,$value);$cache['recurring_prices'][$cycle]=Money::markup($costs[$cycle]['recurring'],$type,$value);}
            $cache['reference_costs']=$costs;
        }
        return Database::transaction(function(PDO $db)use($admin,$id,$input,$old,$cache,$name,$status,$type,$value,$evidence):array{
            $s=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$s->execute([$id]);$current=$s->fetch();$this->checkVersion($current,$input);$this->authorize($admin,$db);
            $db->prepare('UPDATE products SET name=?,status=?,markup_type=?,markup_value=?,config_cache=? WHERE id=?')->execute([$name,$status,$type,$value,json_encode($cache,JSON_THROW_ON_ERROR),$id]);
            $this->audit($db,$admin,$id,'product_update',($evidence!==''?['evidence'=>$evidence]:[])+['before'=>array_intersect_key($old,array_flip(['name','status','markup_type','markup_value'])),'after'=>['name'=>$name,'status'=>$status,'markup_type'=>$type,'markup_value'=>$value],'prices'=>$cache['prices']??[]]);
            return ['product_id'=>$id];
        });
    }
    private function snapshotInput(array $input,?int $id): array
    {
        if($id){$old=$this->read($id);$this->checkVersion($old,$input);return ['product_id'=>$id,'provider_id'=>(int)$old['provider_id'],'remote_pid'=>(int)$old['remote_pid'],'name'=>$old['name'],'type'=>$old['type'],'markup_type'=>$old['markup_type'],'markup_value'=>$old['markup_value'],'billing_cycles'=>$input['billing_cycles']??array_keys(json_decode($old['config_cache'],true)['prices']),'config_options'=>$input['config_options']??null];}
        return array_intersect_key($input,array_flip(['provider_id','remote_pid','name','type','markup_type','markup_value','billing_cycles','config_options']));
    }
    private function prepared(array $input,?int $id): array
    {
        $p=$this->snapshotInput($input,$id);if(($p['config_options']??null)===null){unset($p['config_options']);}
        return (new ProductSnapshotService())->prepare($p);
    }
    private function signature(int $admin,array $p,array $input,int $expires): string
    {
        return Security::requestFingerprint(json_encode([$admin,$expires,$input['expected_version']??null,$p],JSON_THROW_ON_ERROR));
    }
    public function preview(int $admin,array $input,?int $id=null): array
    {
        $this->authorize($admin);$p=$this->prepared($input,$id);$expires=time()+300;
        return ['fields'=>$p['fields'],'defaults'=>$p['config_options'],'prices'=>$p['prices'],'recurring_prices'=>$p['recurring_prices'],'billing_cycles'=>array_keys($p['prices']),'preview_token'=>$expires.'.'.$this->signature($admin,$p,$input,$expires),'expires_at'=>gmdate('Y-m-d H:i:s',$expires)];
    }
    public function snapshot(int $admin,array $input,?int $id=null): array
    {
        $this->authorize($admin);$evidence=$this->evidence($input);$token=Request::text($input,'preview_token',75,75);
        if(!preg_match('/^([0-9]{10})\.([a-f0-9]{64})$/D',$token,$m) || (int)$m[1]<time() || (int)$m[1]>time()+300){throw new ApiException('Snapshot preview expired; preview again',409,409);}
        $operation=hash('sha256',$token);
        // A committed import can be safely found after a lost response, without re-fetching upstream.
        if(!$id && ($done=$this->replay(Database::getInstance(),$admin,$operation))){return ['product_id'=>$done];}
        $p=$this->prepared($input,$id);
        if(!hash_equals($m[2],$this->signature($admin,$p,$input,(int)$m[1]))){throw new ApiException('Preview or upstream changed; preview again',409,409);}
        return Database::transaction(function(PDO $db)use($admin,$input,$id,$p,$evidence,$operation):array{
            // Provider lock serializes imports; same remote product cannot be imported twice from the web.
            $s=$db->prepare('SELECT status FROM providers WHERE id=? FOR UPDATE');$s->execute([$p['provider_id']]);if((int)$s->fetchColumn()!==1){throw new ApiException('Provider disabled',409,409);}
            if($id){$s=$db->prepare('SELECT * FROM products WHERE id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch();$this->checkVersion($old,$input);}
            else{
                if($done=$this->replay($db,$admin,$operation)){return ['product_id'=>$done];}
                $s=$db->prepare('SELECT id FROM products WHERE provider_id=? AND remote_pid=? LIMIT 1 FOR UPDATE');$s->execute([$p['provider_id'],$p['remote_pid']]);if($s->fetchColumn()){throw new ApiException('Product already imported; refresh the existing product',409,409);}
            }
            $this->authorize($admin,$db);$result=(new ProductSnapshotService())->persist($db,$p,'hidden');
            $this->audit($db,$admin,$result['product_id'],$id?'product_snapshot_refresh':'product_import',['evidence'=>$evidence,'operation_key'=>$operation,'before'=>$id?['name'=>$old['name'],'status'=>$old['status'],'snapshot_hash'=>hash('sha256',$old['config_cache'])]:null,'after'=>['provider_id'=>$p['provider_id'],'remote_pid'=>$p['remote_pid'],'billing_cycles'=>array_keys($p['prices']),'config_options'=>$p['config_options'],'schema_hash'=>ConfigOptions::hash($p['fields'])],'prices'=>$p['prices']]);return $result;
        });
    }
    private function replay(PDO $db,int $admin,string $operation): ?int
    {
        $s=$db->prepare("SELECT target_id FROM admin_audit_logs WHERE admin_id=? AND action='product_import' AND target_type='product' AND JSON_UNQUOTE(JSON_EXTRACT(context,'$.operation_key'))=? ORDER BY id DESC LIMIT 1");$s->execute([$admin,$operation]);$id=$s->fetchColumn();return $id?(int)$id:null;
    }
    private function audit(PDO $db,int $admin,int $id,string $action,array $context):void
    {
        $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,?,'product',?,?,?)")->execute([$admin,$action,$id,json_encode($context,JSON_THROW_ON_ERROR),Security::getClientIp()]);
    }
}
