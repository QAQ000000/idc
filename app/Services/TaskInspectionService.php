<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Request};
use PDO;

/** Administrator-only projections. Never return task payloads, connection settings or credentials. */
final class TaskInspectionService
{
    private const COLUMNS = 't.id,t.instance_id,t.invoice_id,t.action,t.status,t.execution_token,t.upstream_task_id,t.last_error,t.retry_count,t.max_retries,t.lease_until,t.next_retry_at,t.created_at,t.updated_at,i.user_id,i.name AS instance_name,i.status AS instance_status,i.remote_instance_id,v.status AS invoice_status,v.total,p.name AS product_name,r.id AS provider_id,r.name AS provider_name';
    private const JOINS = ' FROM tasks t JOIN instances i ON i.id=t.instance_id LEFT JOIN invoices v ON v.id=t.invoice_id JOIN products p ON p.id=i.product_id JOIN providers r ON r.id=i.provider_id';

    public function listing(array $query): array
    {
        $page = $this->page($query['page'] ?? '1');
        $size = $this->page($query['per_page'] ?? '20', 100);
        $status = $query['status'] ?? 'attention';
        if (!is_string($status) || !in_array($status,['attention','manual','pending','running','investigating','failed','success','all'],true)) { throw new ApiException('Invalid task status'); }
        $where = []; $params = [];
        if ($status === 'attention') { $where[] = "((t.status='failed' AND COALESCE(t.last_error,'') NOT LIKE 'PERMANENTLY_CANCELLED:%' AND COALESCE(t.last_error,'') NOT LIKE 'CANCELLED_BY_REFUND:%' AND COALESCE(t.last_error,'') <> 'CANCELLED_BY_RENEWAL' AND COALESCE(t.last_error,'') NOT LIKE 'ADMIN_VERIFIED:%') OR t.status='investigating' OR (t.status='running' AND (t.lease_until IS NULL OR t.lease_until<=UTC_TIMESTAMP())))"; }
        elseif ($status === 'manual') { $where[] = "(t.status='failed' AND t.last_error LIKE 'MANUAL_AUDIT_REQUIRED:%')"; }
        elseif ($status !== 'all') { $where[] = 't.status=?'; $params[] = $status; }
        if (isset($query['action']) && $query['action'] !== '') {
            if (!is_string($query['action']) || !in_array($query['action'],['create','renew','power'],true)) { throw new ApiException('Invalid task action'); }
            $where[] = 't.action=?'; $params[] = $query['action'];
        }
        foreach (['task_id'=>'t.id','invoice_id'=>'t.invoice_id','instance_id'=>'t.instance_id'] as $key=>$column) {
            if (isset($query[$key]) && $query[$key] !== '') { $where[] = $column.'=?'; $params[] = Request::id($query[$key]); }
        }
        $sql = self::JOINS.($where ? ' WHERE '.implode(' AND ', $where) : '');
        $db = Database::getInstance();
        $s = $db->prepare('SELECT COUNT(*)'.$sql); $s->execute($params); $total = (int)$s->fetchColumn();
        $offset = ($page-1)*$size;
        $s = $db->prepare('SELECT '.self::COLUMNS.$sql." ORDER BY t.id DESC LIMIT {$size} OFFSET {$offset}"); $s->execute($params);
        return ['items'=>array_map($this->safeTask(...),$s->fetchAll()),'total'=>$total,'page'=>$page,'per_page'=>$size];
    }

    public function detail(int $id, array $admin, array $query): array
    {
        $page = $this->page($query['audit_page'] ?? '1');
        return Database::transaction(function (PDO $db) use ($id,$admin,$page): array {
            $s=$db->prepare('SELECT '.self::COLUMNS.self::JOINS.' WHERE t.id=?'); $s->execute([$id]); $task=$s->fetch();
            if (!$task) { throw new ApiException('Task not found',404,404); }
            $s=$db->prepare('SELECT id,user_id,product_id,provider_id,name,status,remote_instance_id,ip_address,billing_cycle,recurring_price,override_price,suspend_type,start_date,due_date FROM instances WHERE id=?'); $s->execute([$task['instance_id']]); $instance=$s->fetch();
            $s=$db->prepare('SELECT id,user_id,instance_id,type,status,total,payment_method,created_at,paid_at FROM invoices WHERE id=?'); $s->execute([$task['invoice_id']]); $invoice=$s->fetch() ?: null;
            $s=$db->prepare('SELECT id,email FROM users WHERE id=?'); $s->execute([$instance['user_id']]); $user=$s->fetch();
            $s=$db->prepare("SELECT id,user_id,amount,currency,status,refund_status,refund_amount,created_at,JSON_UNQUOTE(JSON_EXTRACT(payload,'$.settlement_purpose')) AS settlement_purpose FROM payment_records WHERE invoice_id=? ORDER BY id"); $s->execute([$task['invoice_id']]); $receipts=$s->fetchAll();
            $actions=TaskResolutionService::candidateActions($task,$instance,$invoice,$admin['role']);
            // Projection only: locked current reads in resolve remain the authorization and business gate.
            $s=$db->prepare("SELECT id FROM tasks WHERE instance_id=? AND id<>? AND (status IN ('running','investigating') OR (status='pending' AND action='power') OR (status='failed' AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%')) LIMIT 1"); $s->execute([$instance['id'],$id]);
            $blocked=(bool)$s->fetchColumn();
            $s=$db->prepare("SELECT id FROM invoices WHERE instance_id=? AND status='refund_pending' LIMIT 1"); $s->execute([$instance['id']]); $blocked=$blocked || (bool)$s->fetchColumn();
            $s=$db->prepare("SELECT r.id FROM payment_records r JOIN invoices v ON v.id=r.invoice_id WHERE v.instance_id=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.payload,'$.settlement_purpose')),'cloud_consumption')='cloud_consumption' AND (r.refund_status='pending' OR JSON_EXTRACT(r.payload,'$.awaiting_termination')=true) LIMIT 1"); $s->execute([$instance['id']]); $blocked=$blocked || (bool)$s->fetchColumn();
            if ($blocked) { $actions=[]; }
            $s=$db->prepare('SELECT id FROM commissions WHERE invoice_id=? LIMIT 1'); $s->execute([$task['invoice_id']]); $commission=(bool)$s->fetchColumn();
            $consumption=array_values(array_filter($receipts,static fn($r)=>$r['status']==='success' && $r['settlement_purpose']==='cloud_consumption'));
            $r=$consumption[0]??null;
            if ($commission || count($consumption)!==1 || $r['refund_status']!=='none' || $r['currency']!=='CNY' || (int)$r['user_id']!==(int)$instance['user_id'] || bccomp($r['amount'],$invoice['total']??'0',2)!==0) {
                $actions=array_values(array_diff($actions,['cancel_compensate']));
            }
            $s=$db->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE target_type='task' AND target_id=?"); $s->execute([$id]); $total=(int)$s->fetchColumn();
            $offset=($page-1)*20;
            $s=$db->prepare("SELECT l.id,l.admin_id,a.username,l.action,l.created_at,l.context FROM admin_audit_logs l LEFT JOIN admins a ON a.id=l.admin_id WHERE l.target_type='task' AND l.target_id=? ORDER BY l.id DESC LIMIT 20 OFFSET {$offset}"); $s->execute([$id]);
            $audit=[];
            foreach ($s->fetchAll() as $row) {
                $context=json_decode($row['context']??'{}',true,32,JSON_THROW_ON_ERROR);
                // Explicit fields, never dump arbitrary context introduced by another audit producer.
                $row['context']=array_intersect_key($context,array_flip(['evidence','previous_execution_token','remote_instance_id','payment_record_id','amount']));
                $audit[]=$row;
            }
            return ['task'=>$this->safeTask($task),'instance'=>$instance,'invoice'=>$invoice,'user'=>$user,'receipts'=>$receipts,
                'allowed_actions'=>$actions,'blocked_by_other_operation'=>$blocked,
                'audit'=>['items'=>$audit,'total'=>$total,'page'=>$page,'per_page'=>20]];
        });
    }

    private function page(mixed $value, int $max=100000): int
    {
        $n=Request::id($value);
        if ($n>$max) { throw new ApiException('Pagination limit exceeded'); }
        return $n;
    }
    private function safeTask(array $task): array
    {
        $raw=$task['last_error'];
        // Error codes are operational identifiers; legacy arbitrary errors must not leak upstream response bodies.
        $task['last_error']=$raw===null ? null : (preg_match('/^[A-Z][A-Z0-9_]*(?::[A-Z0-9_]+)*$/D',$raw) ? $raw : 'DETAILS_REDACTED');
        return $task;
    }
}
