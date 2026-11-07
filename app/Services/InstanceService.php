<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Database, ApiException, Security};
final class InstanceService
{
    public function list(int $user, int $page = 1): array
    {
        $s=Database::getInstance()->prepare('SELECT id FROM instances WHERE user_id = ? ORDER BY id DESC LIMIT 50 OFFSET ' . (($page-1)*50));
        $s->execute([$user]);return array_map(fn($id)=>$this->detail($user,(int)$id),$s->fetchAll(\PDO::FETCH_COLUMN));
    }
    public function detail(int $user, int $id): array
    {
        $db=Database::getInstance();
        $s=$db->prepare('SELECT i.id,i.name,i.status,i.remote_instance_id,i.ip_address,i.billing_cycle,i.recurring_price,i.start_date,i.due_date,p.name AS product_name,p.type FROM instances i JOIN products p ON p.id=i.product_id WHERE i.id=? AND i.user_id=?');$s->execute([$id,$user]);$i=$s->fetch();
        if (!$i) { throw new ApiException('Instance not found',404,404); }
        $s=$db->prepare("SELECT id,status FROM invoices WHERE instance_id=? AND type='new' ORDER BY id DESC LIMIT 1");$s->execute([$id]);$invoice=$s->fetch();
        $s=$db->prepare("SELECT status,last_error FROM tasks WHERE instance_id=? AND action='create' ORDER BY id DESC LIMIT 1");$s->execute([$id]);$task=$s->fetch();
        $i['invoice_id']=$invoice ? (int)$invoice['id'] : null;
        $i['delivery_status']=match(true) {
            $i['status']==='active'=>'delivered',
            $invoice && $invoice['status']==='refunded'=>'compensated',
            $i['status']==='terminated'=>'cancelled',
            $invoice && $invoice['status']==='unpaid'=>'awaiting_payment',
            $task && $task['status']==='failed'=>'needs_review',
            $task && $task['status']==='investigating'=>'investigating',
            default=>'provisioning',
        };
        return $i;
    }
    public function credentials(int $user,int $id): array
    {
        $i=$this->detail($user,$id);
        if ($i['status']!=='active') { throw new ApiException('Credentials available after delivery',409,409); }
        $s=Database::getInstance()->prepare('SELECT encrypted_password,config_snapshot FROM instances WHERE id=? AND user_id=?');$s->execute([$id,$user]);$row=$s->fetch();
        $snapshot=json_decode($row['config_snapshot']??'{}',true,32,JSON_THROW_ON_ERROR);
        return ['username'=>$snapshot['delivery']['username']??null, 'password'=>Security::decrypt($row['encrypted_password']??'')];
    }
}
