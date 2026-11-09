<?php
declare(strict_types=1);
namespace App\Services;

use App\Plugins\Notifications\NotificationMessage;
use PDO;

/** Ticket messages and mail placeholders share one local transaction; never call a channel here. */
final class TicketNotificationService
{
    private static function settings(PDO $db): ?array
    {
        // Shared locks define the activation boundary against explicit configuration commits.
        $s=$db->query("SELECT `key`,value FROM system_settings WHERE `key` IN ('notify.mail','notify.ticket_mail') ORDER BY `key` FOR SHARE");
        $rows=[];foreach($s->fetchAll() as $r){$rows[$r['key']]=json_decode($r['value'],true,32,JSON_THROW_ON_ERROR);}
        $mail=$rows['notify.mail']??[];$tickets=$rows['notify.ticket_mail']??[];
        return ($mail['enabled']??false)===true && ($tickets['enabled']??false)===true ? ['mail'=>$mail,'tickets'=>$tickets] : null;
    }

    /** Called only for a newly inserted ordinary message, never a replay or closure record. */
    public function enqueue(PDO $db,int $ticket,int $reply,int $customer,?int $admin): void
    {
        $config=self::settings($db);if(!$config){return;}$prefs=$config['tickets'];
        $recipients=$admin===null?$prefs['admin_ids']:[$customer];
        $insert=$db->prepare("INSERT INTO notification_deliveries(fingerprint,channel,user_id,admin_id,target,context) VALUES(?,'mail',?,?,?,?) ON DUPLICATE KEY UPDATE id=id");
        foreach($recipients as $recipient){
            $s=$db->prepare('SELECT email FROM '.($admin===null?'admins':'users')." WHERE id=? AND status='active'");$s->execute([$recipient]);$target=$s->fetchColumn();
            if(!filter_var($target,FILTER_VALIDATE_EMAIL)){continue;}
            $context=['kind'=>'ticket_update','ticket_id'=>$ticket,'reply_id'=>$reply,'customer_id'=>$customer,
                'author_type'=>$admin===null?'customer':'admin','author_id'=>$admin??$customer,'activation'=>$prefs['activation']];
            $insert->execute(['ticket:'.$ticket.':reply:'.$reply.':'.($admin===null?'admin:':'user:').$recipient,
                $admin===null?null:$recipient,$admin===null?$recipient:null,$target,json_encode($context,JSON_THROW_ON_ERROR)]);
        }
    }

    /** Recipient -> ticket -> reply -> configuration, then the caller locks the delivery row. */
    public function message(PDO $db,array $delivery,array $context): ?NotificationMessage
    {
        $toAdmin=$delivery['admin_id']!==null && $delivery['user_id']===null;
        $toCustomer=$delivery['user_id']!==null && $delivery['admin_id']===null;
        if(!$toAdmin&&!$toCustomer){return null;}
        $recipient=(int)($toAdmin?$delivery['admin_id']:$delivery['user_id']);
        $s=$db->prepare('SELECT email,status FROM '.($toAdmin?'admins':'users').' WHERE id=? FOR UPDATE');$s->execute([$recipient]);$target=$s->fetch();
        $s=$db->prepare('SELECT id,user_id FROM tickets WHERE id=? FOR UPDATE');$s->execute([$context['ticket_id']??0]);$t=$s->fetch();
        $s=$db->prepare('SELECT ticket_id,user_id,admin_id,type FROM ticket_replies WHERE id=? FOR SHARE');$s->execute([$context['reply_id']??0]);$r=$s->fetch();
        $config=self::settings($db);if(!$config){return null;}$prefs=$config['tickets'];$mail=$config['mail'];
        $valid=$target && $target['status']==='active' && $target['email']===$delivery['target'] && $t && $r
            && (int)$t['user_id']===($context['customer_id']??null) && (int)$r['ticket_id']===(int)$t['id'] && $r['type']==='reply'
            && ($context['activation']??null)===$prefs['activation'];
        if($toAdmin){
            $valid=$valid && in_array($recipient,$prefs['admin_ids'],true) && ($context['author_type']??null)==='customer'
                && $r['admin_id']===null && (int)$r['user_id']===(int)$t['user_id'] && (int)$r['user_id']===($context['author_id']??null);
        }else{
            $valid=$valid && $recipient===(int)$t['user_id'] && ($context['author_type']??null)==='admin'
                && $r['user_id']===null && $r['admin_id']!==null && (int)$r['admin_id']===($context['author_id']??null);
        }
        if(!$valid){return null;}
        $link=$mail['site_url'].($toAdmin?'/admin':'').'/tickets/'.$t['id'].'?page=last';
        return new NotificationMessage($delivery['fingerprint'],($mail['subject_prefix']??'').'工单 #'.$t['id'].' 有新消息',
            '工单 #'.$t['id'].($toAdmin?' 有新的客户消息。':' 有新的客服公开回复。')."\n请登录后查看完整会话：\n".$link."\n本邮件仅为更新提示，不构成业务操作授权。");
    }
}
