<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException,Database,Money,Request,Security};
use PDO;

/** Already performed by an operator: settle money and future price, never submit upstream work. */
final class ManualAdjustmentService
{
    private function authorize(PDO $db,int $admin,bool $write=false,bool $lock=false): string
    {
        $s=$db->prepare("SELECT role FROM admins WHERE id=? AND status='active'".($lock?' FOR UPDATE':''));$s->execute([$admin]);$role=$s->fetchColumn();
        if(!in_array($role,$write?['super_admin']:['super_admin','finance'],true)){throw new ApiException('Financial read access or active super_admin required',403,403);}
        return $role;
    }

    private function instance(PDO $db,int $id): array
    {
        $s=$db->prepare('SELECT * FROM instances WHERE id=? FOR UPDATE');$s->execute([$id]);
        return $s->fetch()?:throw new ApiException('Instance not found',404,404);
    }

    private function facts(PDO $db,array $i): array
    {
        TaskGate::assertClear($db,(int)$i['id']);
        if($i['status']!=='active'||!$i['remote_instance_id']||!in_array($i['billing_cycle'],['monthly','annually'],true)
            ||InstanceServiceStatusService::renewalBlocked($i)){throw new ApiException('A delivered active resource without service review is required',409,409);}
        $s=$db->prepare('SELECT * FROM tasks WHERE instance_id=? ORDER BY id FOR UPDATE');$s->execute([$i['id']]);$tasks=$s->fetchAll();
        foreach($tasks as $t){if($t['status']==='pending'){throw new ApiException('Complete queued operations before settling an adjustment',409,409);}}
        $s=$db->prepare('SELECT * FROM invoices WHERE instance_id=? ORDER BY id FOR UPDATE');$s->execute([$i['id']]);$invoices=$s->fetchAll();
        foreach($invoices as $v){if((int)$v['user_id']!==(int)$i['user_id']){throw new ApiException('Invoice ownership mismatch',409,409);}}
        $s=$db->prepare("SELECT id,admin_id,action,context,created_at FROM admin_audit_logs WHERE target_type='instance' AND target_id=? AND action IN ('instance_renewal_price','instance_manual_adjustment') ORDER BY id FOR UPDATE");$s->execute([$i['id']]);$audit=$s->fetchAll();
        $s=$db->prepare('SELECT commission_rate FROM products WHERE id=? FOR SHARE');$s->execute([$i['product_id']]);$rate=$s->fetchColumn();
        if($rate===false||bccomp((string)$rate,'0',2)!==0){throw new ApiException('Commission-bearing adjustments are not enabled',409,409);}
        $s=$db->prepare('SELECT status FROM users WHERE id=?');$s->execute([$i['user_id']]);
        if($s->fetchColumn()!=='active'){throw new ApiException('Customer account unavailable',409,409);}
        return ['invoices'=>$invoices,'tasks'=>$tasks,'audit'=>$audit,
            'version'=>Security::requestFingerprint(json_encode([$i,$invoices,$tasks,$audit],JSON_THROW_ON_ERROR))];
    }

    private static function failBasis(): never { throw new ApiException('Pricing basis cannot prove one current paid period; manual reconciliation required (multi-period prepayment and adjusted historical dates are not supported)',409,409); }

    /** Calendar arithmetic must use the original start, never subtract a month from a clamped end. */
    private function end(PDO $db,string $start,string $cycle): string
    {
        $interval=$cycle==='monthly'?'1 MONTH':'1 YEAR';
        $s=$db->prepare("SELECT DATE_FORMAT(DATE_ADD(?,INTERVAL $interval),'%Y-%m-%d %H:%i:%s')");$s->execute([$start]);return (string)$s->fetchColumn();
    }

    private function basis(PDO $db,array $i,array $facts,string $at): array
    {
        if(!$i['due_date']||!$i['start_date']||$i['due_date']<=$at){self::failBasis();}
        $candidates=[];$successful=[];
        foreach($facts['tasks'] as $t){if($t['status']==='success'){$successful[$t['invoice_id'].':'.$t['action']]=true;}}
        foreach($facts['invoices'] as $v){
            if($v['status']!=='paid'||!in_array($v['type'],['new','renew'],true)){continue;}
            $p=json_decode($v['price_snapshot'],true,64,JSON_THROW_ON_ERROR);
            if($v['type']==='new'){
                $start=$i['start_date'];$end=$this->end($db,$start,$i['billing_cycle']);
                $valid=isset($successful[$v['id'].':create'])&&($p['billing_cycle']??null)===$i['billing_cycle']
                    &&($p['product_id']??null)===(int)$i['product_id']&&($p['recurring_price']??null)===$v['total']&&($p['total']??null)===$v['total'];
            }else{
                $start=$p['base_due_date']??'';$end=$p['target_due_date']??'';
                $valid=isset($successful[$v['id'].':renew'])&&($p['billing_cycle']??null)===$i['billing_cycle']
                    &&($p['provider_id']??null)===(int)$i['provider_id']&&($p['remote_instance_id']??null)===$i['remote_instance_id']
                    &&($p['total']??null)===$v['total']&&$start!==''&&$this->end($db,$start,$i['billing_cycle'])===$end;
            }
            if(!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$start)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$end)){self::failBasis();}
            // A future paid period proves multi-period prepayment; do not charge just its final segment.
            if($start>$at||$end>$i['due_date']){self::failBasis();}
            if($start<=$at&&$end>$at){if(!$valid||$end!==$i['due_date']){self::failBasis();}$candidates[]=['invoice_id'=>(int)$v['id'],'period_start'=>$start,'period_end'=>$end,'original_price'=>Money::positive($v['total'])];}
        }
        if(count($candidates)!==1){self::failBasis();}
        $basis=$candidates[0];$coverage=$basis['original_price'];$ids=[];
        foreach($facts['invoices'] as $v){
            if($v['type']!=='upgrade'||!in_array($v['status'],['paid','refund_pending','refunded'],true)){continue;}
            $p=json_decode($v['price_snapshot'],true,64,JSON_THROW_ON_ERROR);
            if($v['status']!=='paid'||($p['kind']??null)!=='manual_adjustment_v1'){self::failBasis();}
            $a=null;
            foreach($facts['audit'] as $row){$c=json_decode($row['context'],true,64,JSON_THROW_ON_ERROR);if($row['action']==='instance_manual_adjustment'&&($c['invoice_id']??null)===(int)$v['id']&&($p['admin_id']??null)===(int)$row['admin_id']){$a=$c;break;}}
            if(!$a||($a['total']??null)!==$v['total']){self::failBasis();}
            if(($p['basis']['invoice_id']??null)!==$basis['invoice_id']){continue;}
            if(($p['basis']['period_start']??null)!==$basis['period_start']||($p['basis']['period_end']??null)!==$basis['period_end']||($p['coverage_before']??null)!==$coverage){self::failBasis();}
            $next=Money::positive($p['coverage_after']??null);if(bccomp($next,$coverage,2)<0){self::failBasis();}
            $coverage=$next;$ids[]=(int)$v['id'];
        }
        return $basis+['covered_price'=>$coverage,'prior_adjustment_ids'=>$ids];
    }

    public static function prorate(string $target,string $covered,int $remaining,int $duration): string
    {
        $target=Money::positive($target);$covered=Money::positive($covered);
        if($duration<1||$remaining<1||$remaining>$duration){throw new ApiException('Invalid adjustment period',409,409);}
        $difference=bcsub($target,$covered,2);if(bccomp($difference,'0',2)<=0){return '0.00';}
        return bcadd(bcdiv(bcmul($difference,(string)$remaining,6),(string)$duration,6),'0.005',2);
    }

    private function quote(PDO $db,array $i,array $facts,string $price,string $at): array
    {
        $basis=$this->basis($db,$i,$facts,$at);$duration=strtotime($basis['period_end'].' UTC')-strtotime($basis['period_start'].' UTC');$remaining=strtotime($basis['period_end'].' UTC')-strtotime($at.' UTC');
        return ['basis'=>$basis,'new_price'=>$price,'calculated_at'=>$at,'period_seconds'=>$duration,'remaining_seconds'=>$remaining,
            'coverage_before'=>$basis['covered_price'],'coverage_after'=>bccomp($price,$basis['covered_price'],2)>0?$price:$basis['covered_price'],
            'total'=>self::prorate($price,$basis['covered_price'],$remaining,$duration),
            'cancelled_invoice_ids'=>array_values(array_map(static fn($v)=>(int)$v['id'],array_filter($facts['invoices'],static fn($v)=>$v['type']==='renew'&&$v['status']==='unpaid')))];
    }

    public function detail(int $admin,int $id): array
    {
        $this->authorize(Database::getInstance(),$admin);
        return Database::transaction(function(PDO $db)use($admin,$id):array{
            $i=$this->instance($db,$id);$blocked=null;$basis=null;
            try{$facts=$this->facts($db,$i);$basis=$this->basis($db,$i,$facts,gmdate('Y-m-d H:i:s'));}catch(ApiException $e){if($e->httpStatus!==409){throw $e;}$blocked=$e->getMessage();}
            $role=$this->authorize($db,$admin,false,true);
            $s=$db->prepare("SELECT id,admin_id,created_at,JSON_EXTRACT(context,'$.invoice_id') AS invoice_id,JSON_UNQUOTE(JSON_EXTRACT(context,'$.evidence')) AS evidence,JSON_UNQUOTE(JSON_EXTRACT(context,'$.total')) AS total,JSON_UNQUOTE(JSON_EXTRACT(context,'$.new_price')) AS new_price FROM admin_audit_logs WHERE target_type='instance' AND target_id=? AND action='instance_manual_adjustment' ORDER BY id DESC LIMIT 20");$s->execute([$id]);
            return ['instance_id'=>$id,'effective_price'=>$i['override_price']??$i['recurring_price'],'billing_cycle'=>$i['billing_cycle'],
                'basis'=>$basis,'editable'=>$role==='super_admin'&&$blocked===null,'blocked_reason'=>$blocked,'audit'=>$s->fetchAll()];
        });
    }

    public function preview(int $admin,int $id,array $input): array
    {
        $this->authorize(Database::getInstance(),$admin,true);
        foreach(array_keys($input) as $k){if(!in_array($k,['new_price','evidence'],true)){throw new ApiException('Unsupported adjustment field');}}
        $price=Money::positive($input['new_price']??null);$evidence=trim(Request::text($input,'evidence',10,1000));if(strlen($evidence)<10){throw new ApiException('Completion evidence required');}
        return Database::transaction(function(PDO $db)use($admin,$id,$price,$evidence):array{
            $i=$this->instance($db,$id);$facts=$this->facts($db,$i);$this->authorize($db,$admin,true,true);
            $q=$this->quote($db,$i,$facts,$price,gmdate('Y-m-d H:i:s'));
            $payload=['admin'=>$admin,'instance'=>$id,'version'=>$facts['version'],'quote'=>$q,'evidence'=>$evidence,'expires'=>time()+300];
            $encoded=rtrim(strtr(base64_encode(json_encode($payload,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
            return $q+['preview_token'=>$encoded.'.'.Security::requestFingerprint($encoded),'expires_at'=>gmdate('Y-m-d H:i:s',$payload['expires'])];
        });
    }

    public function settle(int $admin,int $id,array $input): array
    {
        $this->authorize(Database::getInstance(),$admin,true);
        foreach(array_keys($input) as $k){if(!in_array($k,['preview_token','request_key','confirmed'],true)){throw new ApiException('Unsupported settlement field');}}
        if(($input['confirmed']??null)!==true){throw new ApiException('Explicit completion and wallet debit confirmation required');}
        $token=Request::text($input,'preview_token',10,16000);$key=Request::text($input,'request_key',16,48);
        if(!preg_match('/^[a-zA-Z0-9_-]{16,48}$/D',$key)){throw new ApiException('Invalid request_key');}
        $parts=explode('.',$token);if(count($parts)!==2||!hash_equals(Security::requestFingerprint($parts[0]),$parts[1])){throw new ApiException('Invalid adjustment preview',409,409);}
        try{$p=json_decode(base64_decode(strtr($parts[0],'-_','+/'),true)?:'',true,64,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new ApiException('Invalid adjustment preview',409,409);}
        if(($p['admin']??null)!==$admin||($p['instance']??null)!==$id){throw new ApiException('Preview identity mismatch',409,409);}
        $hash=Security::requestFingerprint($token);$checkoutKey='manual:'.$key;
        return Database::transaction(function(PDO $db)use($admin,$id,$p,$hash,$checkoutKey):array{
            $i=$this->instance($db,$id);$this->authorize($db,$admin,true,true);
            $s=$db->prepare('SELECT id,checkout_hash FROM invoices WHERE user_id=? AND checkout_key=? FOR UPDATE');$s->execute([$i['user_id'],$checkoutKey]);
            if($v=$s->fetch()){if(!hash_equals($v['checkout_hash'],$hash)){throw new ApiException('Request key already used for another adjustment',409,409);}return ['invoice_id'=>(int)$v['id'],'replayed'=>true];}
            if(($p['expires']??0)<=time()||($p['quote']['basis']['period_end']??'')<=gmdate('Y-m-d H:i:s')){throw new ApiException('Adjustment preview expired; review again',409,409);}
            $facts=$this->facts($db,$i);
            if(!hash_equals($facts['version'],$p['version']??'')){throw new ApiException('Price, period or operations changed; preview again',409,409);}
            $q=$this->quote($db,$i,$facts,$p['quote']['new_price'],$p['quote']['calculated_at']);
            if($q!==$p['quote']){throw new ApiException('Pricing basis changed; preview again',409,409);}
            $snapshot=$q+['kind'=>'manual_adjustment_v1','admin_id'=>$admin,'completed_at'=>gmdate('Y-m-d H:i:s'),'recurring_price'=>$q['new_price']];
            $db->prepare("INSERT INTO invoices(user_id,instance_id,type,total,price_snapshot,checkout_key,checkout_hash) VALUES(?,?,'upgrade',?,?,?,?)")->execute([$i['user_id'],$id,$q['total'],json_encode($snapshot,JSON_THROW_ON_ERROR),$checkoutKey,$hash]);$invoice=(int)$db->lastInsertId();
            $s=$db->prepare("UPDATE invoices SET status='paid',payment_method=?,paid_at=UTC_TIMESTAMP() WHERE id=? AND status='unpaid'");$s->execute([$q['total']==='0.00'?'manual_zero':'balance',$invoice]);if($s->rowCount()!==1){throw new ApiException('Settlement conflict',409,409);}
            if($q['total']!=='0.00'){
                (new LedgerService())->wallet($db,(int)$i['user_id'],$invoice,bcsub('0',$q['total'],2),'pay_invoice','pay_invoice:inv_'.$invoice);
                $db->prepare('UPDATE users SET total_spent=total_spent+? WHERE id=?')->execute([$q['total'],$i['user_id']]);
                $db->prepare("INSERT INTO payment_records(invoice_id,user_id,channel,trade_no,amount,status,paid_at,payload) VALUES(?,?,'balance',?,?,'success',UTC_TIMESTAMP(),'{\"settlement_purpose\":\"cloud_consumption\"}')")->execute([$invoice,$i['user_id'],'balance:inv_'.$invoice,$q['total']]);
            }else{
                $s=$db->prepare("SELECT id FROM users WHERE id=? AND status='active' FOR UPDATE");$s->execute([$i['user_id']]);if(!$s->fetchColumn()){throw new ApiException('Customer unavailable',409,409);}
            }
            $unpaid=array_values(array_filter($facts['invoices'],static fn($v)=>$v['type']==='renew'&&$v['status']==='unpaid'));
            InstanceRenewalPriceService::cancelUnpaid($db,$i,$unpaid);
            $db->prepare('UPDATE instances SET override_price=? WHERE id=?')->execute([$q['new_price'],$id]);
            $context=['invoice_id'=>$invoice,'evidence'=>$p['evidence'],'total'=>$q['total'],'new_price'=>$q['new_price'],'previous_effective_price'=>$i['override_price']??$i['recurring_price'],'basis'=>$q['basis'],'coverage_before'=>$q['coverage_before'],'coverage_after'=>$q['coverage_after'],'cancelled_invoice_ids'=>$q['cancelled_invoice_ids']];
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'instance_manual_adjustment','instance',?,?,?)")->execute([$admin,$id,json_encode($context,JSON_THROW_ON_ERROR),Security::getClientIp()]);
            return ['invoice_id'=>$invoice,'total'=>$q['total'],'new_price'=>$q['new_price'],'cancelled_invoice_ids'=>$q['cancelled_invoice_ids'],'replayed'=>false];
        },3);
    }
}
