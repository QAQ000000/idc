<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use App\Plugins\Notifications\{NotificationRegistry, NotificationMessage, NotificationResult};
use PDO;

/** Persistent single-claim sender. Notification state never changes funds, tasks or resources. */
final class NotificationService
{
    public function __construct(private readonly ?NotificationRegistry $registry=null) {}

    public static function reminderDay(): string { return gmdate('Y-m-d',time()+8*3600); }

    /** Reliable source sweep: no SMTP call, no coupling to the financial transaction commit. */
    public function generate(): int
    {
        $settings=(new NotificationSettingsService())->load();
        if (!$settings) { return 0; }
        $db=Database::getInstance();$count=0;
        $insert=$db->prepare("INSERT INTO notification_deliveries(fingerprint,channel,user_id,admin_id,target,context) VALUES(?,'mail',?,?,?,?)
            ON DUPLICATE KEY UPDATE id=id");
        if ($settings['task_alerts']) {
            $cursor=0;
            do {
                $s=$db->prepare("SELECT id,instance_id,execution_token FROM tasks WHERE id>? AND status='failed' AND last_error LIKE 'MANUAL_AUDIT_REQUIRED:%' ORDER BY id LIMIT 100");
                $s->execute([$cursor]);$tasks=$s->fetchAll();
                foreach ($tasks as $t) {
                    $cursor=(int)$t['id'];
                    foreach ($settings['admin_ids'] as $aid) {
                        $s=$db->prepare("SELECT email FROM admins WHERE id=? AND status='active'");$s->execute([$aid]);$target=$s->fetchColumn();
                        if (!filter_var($target,FILTER_VALIDATE_EMAIL)) { continue; }
                        $context=['kind'=>'task_manual','task_id'=>$cursor,'instance_id'=>(int)$t['instance_id'],'execution_token'=>(int)$t['execution_token']];
                        $insert->execute(['task:'.$cursor.':manual:'.$t['execution_token'].':admin:'.$aid,null,$aid,$target,json_encode($context,JSON_THROW_ON_ERROR)]);
                        $count+=(int)($insert->rowCount()===1);
                    }
                }
            } while (count($tasks)===100);
        }
        if ($settings['due_reminders']) {
            $cursor=0;$day=self::reminderDay();
            do {
                $s=$db->prepare("SELECT i.id,i.user_id,i.due_date,u.email FROM instances i JOIN users u ON u.id=i.user_id
                    WHERE i.id>? AND i.status='active' AND u.status='active' AND i.remote_instance_id IS NOT NULL AND i.billing_cycle IN ('monthly','annually')
                    AND i.due_date>UTC_TIMESTAMP() AND i.due_date<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) ORDER BY i.id LIMIT 100");
                $s->execute([$cursor]);$instances=$s->fetchAll();
                foreach ($instances as $i) {
                    $cursor=(int)$i['id'];
                    if (!filter_var($i['email'],FILTER_VALIDATE_EMAIL)) { continue; }
                    $context=['kind'=>'due_reminder','instance_id'=>$cursor,'due_date'=>$i['due_date'],'reminder_day'=>$day];
                    $insert->execute(['host:'.$cursor.':due_reminder:'.str_replace(['-',':',' '],'',$i['due_date']).':'.$day,
                        $i['user_id'],null,$i['email'],json_encode($context,JSON_THROW_ON_ERROR)]);
                    $count+=(int)($insert->rowCount()===1);
                }
            } while (count($instances)===100);
        }
        return $count;
    }

    /** Instance/task/recipient current reads precede the delivery row lock, never a network-held lock. */
    public function claim(int $id,array $settings): ?array
    {
        return Database::transaction(static function(PDO $db) use($id,$settings): ?array {
            $s=$db->prepare("SELECT * FROM notification_deliveries WHERE id=? AND channel='mail' AND status='pending'");$s->execute([$id]);$preview=$s->fetch();
            if (!$preview) { return null; }
            $c=json_decode($preview['context']??'{}',true,32,JSON_THROW_ON_ERROR);
            $i=null;
            if (!in_array($c['kind']??null,['password_reset','ticket_update'],true)) {
                $s=$db->prepare('SELECT *,due_date>UTC_TIMESTAMP() AND due_date<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY) AS due_now FROM instances WHERE id=? FOR UPDATE');
                $s->execute([$c['instance_id']??0]);$i=$s->fetch();
            }
            $valid=false;$message=null;
            if (($c['kind']??null)==='ticket_update') {
                $message=(new TicketNotificationService())->message($db,$preview,$c);$valid=$message!==null;
            } elseif (($c['kind']??null)==='password_reset' && $preview['admin_id']===null && $preview['user_id']!==null) {
                $s=$db->prepare('SELECT id,email,status,auth_version FROM users WHERE id=? FOR UPDATE');$s->execute([$preview['user_id']]);$u=$s->fetch();
                $token=Security::decrypt($c['encrypted_token']??'');$p=$token===null?null:PasswordService::resetClaims($token);
                $valid=$u && $p && $u['status']==='active' && $u['email']===$preview['target'] && (int)$u['id']===$p['uid']
                    && (int)$u['auth_version']===$p['av'] && hash_equals(Security::requestFingerprint($u['email']),$p['email_hash']);
                if ($valid) {
                    $message=new NotificationMessage($preview['fingerprint'],($settings['subject_prefix']??'').'重置账户登录密码',
                        "你申请了重置账户登录密码。链接自申请起 15 分钟内有效，一次成功后所有旧登录和旧重置链接失效。\n".
                        $settings['site_url'].'/reset-password#token='.rawurlencode($token)."\n如果不是你本人申请，请忽略本邮件；账户密码不会因此改变。请勿转发此链接。");
                }
            } elseif (($c['kind']??null)==='task_manual' && $settings['task_alerts'] && $preview['user_id']===null && $preview['admin_id']!==null) {
                $s=$db->prepare('SELECT id,instance_id,status,execution_token,last_error,action,invoice_id FROM tasks WHERE id=? FOR UPDATE');$s->execute([$c['task_id']??0]);$t=$s->fetch();
                $s=$db->prepare("SELECT email,status FROM admins WHERE id=? FOR UPDATE");$s->execute([$preview['admin_id']]);$a=$s->fetch();
                $valid=$i && $t && (int)$t['instance_id']===(int)$i['id'] && $t['status']==='failed'
                    && str_starts_with($t['last_error']??'','MANUAL_AUDIT_REQUIRED:') && (int)$t['execution_token']===($c['execution_token']??null)
                    && $a && $a['status']==='active' && $a['email']===$preview['target'] && in_array((int)$preview['admin_id'],$settings['admin_ids'],true);
                if ($valid) {
                    $message=new NotificationMessage($preview['fingerprint'],($settings['subject_prefix']??'').'任务 #'.$t['id'].' 需要人工核验',
                        "任务 #{$t['id']} 已进入人工核验。\n操作：{$t['action']}\n实例：#{$i['id']}\n账单：#".($t['invoice_id']??'无').
                        "\n请登录管理员后台查看证据及允许的处理动作：\n".$settings['site_url'].'/admin/tasks/'.$t['id']."\n邮件不构成操作授权，不会自动退款或重发任务。");
                }
            } elseif (($c['kind']??null)==='due_reminder' && $settings['due_reminders'] && $preview['admin_id']===null && $preview['user_id']!==null) {
                $s=$db->prepare('SELECT email,status FROM users WHERE id=? FOR UPDATE');$s->execute([$preview['user_id']]);$u=$s->fetch();
                $valid=$i && (int)$i['user_id']===(int)$preview['user_id'] && $i['status']==='active' && $i['remote_instance_id']!==null
                    && in_array($i['billing_cycle'],['monthly','annually'],true) && $i['due_now'] && $i['due_date']===($c['due_date']??null)
                    && ($c['reminder_day']??null)===self::reminderDay() && $u && $u['status']==='active' && $u['email']===$preview['target'];
                if ($valid) {
                    $due=(new \DateTimeImmutable($i['due_date'],new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s');
                    $message=new NotificationMessage($preview['fingerprint'],($settings['subject_prefix']??'').'实例 #'.$i['id'].' 即将到期',
                        "实例 #{$i['id']} 将于 {$due}（北京时间）到期。\n如需继续使用，请登录控制台查看当前账期及续费状态：\n".
                        $settings['site_url'].'/instances/'.$i['id']."\n若已续费，请以控制台最新账期为准；本邮件不证明上游续费已完成。");
                }
            }
            // A save may commit after the sender loaded its SMTP document but before claim.
            // Leave eligible mail pending for a fresh load; never send with a superseded password or switch.
            $current=$db->query("SELECT value,encrypted_credentials FROM system_settings WHERE `key`='notify.mail' FOR SHARE")->fetch();
            if($valid && (!$current || !hash_equals($settings['settings_revision']??'',NotificationSettingsService::senderRevision($current)))){return null;}
            $s=$db->prepare("SELECT * FROM notification_deliveries WHERE id=? AND channel='mail' FOR UPDATE");$s->execute([$id]);$d=$s->fetch();
            if (!$d || $d['status']!=='pending') { return null; }
            // The event identity/recipient is immutable after insertion; never retarget an existing claim.
            foreach (['fingerprint','target','user_id','admin_id','context'] as $key) {
                if ($d[$key]!==$preview[$key]) { throw new \RuntimeException('Notification identity changed'); }
            }
            $s=$db->prepare("UPDATE notification_deliveries SET status=?,error_message=?,context=JSON_REMOVE(context,'$.encrypted_token'),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending'");
            $s->execute([$valid?'sending':'failed',$valid?null:'STALE_BUSINESS_CONTEXT',$id]);
            return $valid ? ['id'=>$id,'target'=>$d['target'],'message'=>$message] : null;
        },3);
    }

    public function finish(int $id,NotificationResult $result): bool
    {
        $status=match($result->status) {'accepted'=>'success','rejected'=>'failed',default=>'pending_manual_check'};
        $s=Database::getInstance()->prepare("UPDATE notification_deliveries SET status=?,error_message=?,context=JSON_REMOVE(context,'$.encrypted_token'),sent_at=IF(?='success',UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='sending'");
        $s->execute([$status,$result->code,$status,$id]);$changed=$s->rowCount()===1;
        if ($changed && $status==='pending_manual_check') { logger()->critical('Notification manual review required',['delivery_id'=>$id,'channel'=>'mail','result_code'=>$result->code]); }
        return $changed;
    }

    public function recover(): int
    {
        $s=Database::getInstance()->prepare("UPDATE notification_deliveries SET status='pending_manual_check',error_message='SEND_OUTCOME_UNKNOWN',context=JSON_REMOVE(context,'$.encrypted_token'),updated_at=UTC_TIMESTAMP()
            WHERE channel='mail' AND status='sending' AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");$s->execute();$count=$s->rowCount();
        if ($count) { logger()->critical('Stale notification claims require manual review',['count'=>$count,'channel'=>'mail']); }
        return $count;
    }

    /** Operator/test one-shot. The production scheduler calls sweep(), never SMTP. */
    public function dispatch(): array
    {
        $recovered=$this->recover();$settings=(new NotificationSettingsService())->load();
        if (!$settings) { return ['queued'=>0,'processed'=>0,'recovered'=>$recovered,'enabled'=>false]; }
        $queued=$this->generate();$processed=0;
        $rows=Database::getInstance()->query("SELECT id FROM notification_deliveries WHERE channel='mail' AND status='pending' ORDER BY id LIMIT 20")->fetchAll();
        foreach ($rows as $row) {
            if ($this->sendOne((int)$row['id'])) { $processed++; }
        }
        return ['queued'=>$queued,'processed'=>$processed,'recovered'=>$recovered,'enabled'=>true];
    }

    public function sweep(): array { return ['recovered'=>$this->recover(),'queued'=>$this->generate()]; }

    private function sendOne(int $id):bool
    {
        $settings=(new NotificationSettingsService())->load();if (!$settings) { return false; }
        $driver=($this->registry??NotificationRegistry::installed())->get('mail');
        $claim=$this->claim($id,$settings);if (!$claim) { return false; }
        try { $result=$driver->send($claim['target'],$claim['message'],$settings['config']); }
        catch (\Throwable) { $result=new NotificationResult('unknown','CHANNEL_OUTCOME_UNKNOWN'); }
        $this->finish($claim['id'],$result);return true;
    }

    /** Sender is a separate CLI process so SMTP cannot stall financial Outbox or scheduler commands. */
    public function runOnce():bool
    {
        if (!(new NotificationSettingsService())->load()) { return false; }
        $ids=Database::getInstance()->query("SELECT id FROM notification_deliveries WHERE channel='mail' AND status='pending' ORDER BY id LIMIT 20")->fetchAll();
        foreach ($ids as $row) { if ($this->sendOne((int)$row['id'])) { return true; } }
        return false;
    }

    public function inspect(): array
    {
        // Local operator projection; no SMTP config, target or arbitrary context in shared logs.
        return Database::getInstance()->query("SELECT id,fingerprint,channel,user_id,admin_id,status,error_message,sent_at,created_at,updated_at
            FROM notification_deliveries WHERE channel='mail' AND status IN ('pending','sending','pending_manual_check','failed') ORDER BY id DESC LIMIT 100")->fetchAll();
    }

    public function resolve(int $admin,int $id,array $input):void
    {
        $evidence=trim(Request::text($input,'evidence',10,1000));$status=$input['status']??null;
        if (strlen($evidence)<10 || !in_array($status,['success','failed'],true)
            || ($input['expected_status']??null)!=='pending_manual_check') { throw new ApiException('Manual evidence and an explicit accepted/rejected outcome required'); }
        Database::transaction(static function(PDO $db) use($admin,$id,$status,$evidence):void {
            NotificationSettingsService::authorize($db,$admin);
            $s=$db->prepare("SELECT status FROM notification_deliveries WHERE id=? AND channel='mail' FOR UPDATE");$s->execute([$id]);
            if ($s->fetchColumn()!=='pending_manual_check') { throw new ApiException('Notification is no longer awaiting review',409,409); }
            $db->prepare("UPDATE notification_deliveries SET status=?,error_message='ADMIN_VERIFIED',sent_at=IF(?='success',UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP() WHERE id=? AND status='pending_manual_check'")->execute([$status,$status,$id]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'notification_resolve','notification',?,?,?)")
                ->execute([$admin,$id,json_encode(['status'=>$status,'evidence'=>$evidence],JSON_THROW_ON_ERROR),Security::getClientIp()]);
        });
    }
}
