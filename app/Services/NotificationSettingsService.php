<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Security};
use App\Plugins\Notifications\NotificationRegistry;
use Illuminate\Support\Facades\Validator;
use PDO;

final class NotificationSettingsService
{
    public function __construct(private readonly ?NotificationRegistry $registry = null) {}

    /** Local operator configuration only. Secrets arrive on stdin and are encrypted as one document. */
    public function configure(int $admin, array $input): void
    {
        $rules=['enabled'=>'required|boolean','task_alerts'=>'required|boolean','due_reminders'=>'required|boolean','site_url'=>'required|url:http,https|max:255',
            'admin_ids'=>'required|array|min:1|max:20','admin_ids.*'=>'required|integer|min:1|distinct', 'config'=>'required|array',
            'subject_prefix'=>['sometimes','string','max:40','not_regex:/[\x00-\x1f\x7f]/']];
        if (Validator::make($input,$rules)->fails() || !is_bool($input['enabled']??null)
            || !is_bool($input['task_alerts']??null) || !is_bool($input['due_reminders']??null)) { throw new ApiException('Invalid notification settings'); }
        $url=parse_url($input['site_url']);
        if (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || !in_array($url['path']??'', ['', '/'],true)
            || (!app()->environment(['local','test']) && ($url['scheme']??'')!=='https')) { throw new ApiException('Notification site URL must be a trusted site origin'); }
        $driver=($this->registry??NotificationRegistry::installed())->get('mail');$schema=$driver->getConfigSchema();
        if (array_diff(array_keys($input['config']),array_keys($schema)) || Validator::make($input['config'],$schema)->fails()) {
            throw new ApiException('Invalid private mail configuration');
        }
        $public=['enabled'=>$input['enabled'],'task_alerts'=>$input['task_alerts'],'due_reminders'=>$input['due_reminders'],'site_url'=>rtrim($input['site_url'],'/'),
            'admin_ids'=>array_map('intval',array_values($input['admin_ids'])),'subject_prefix'=>$input['subject_prefix']??''];
        $encrypted=Security::encrypt(json_encode($input['config'],JSON_THROW_ON_ERROR));
        Database::transaction(static function(PDO $db) use($admin,$public,$encrypted):void {
            self::authorize($db,$admin);
            foreach ($public['admin_ids'] as $recipient) {
                $s=$db->prepare("SELECT email FROM admins WHERE id=? AND status='active' FOR UPDATE");$s->execute([$recipient]);
                if (!filter_var($s->fetchColumn(),FILTER_VALIDATE_EMAIL)) { throw new ApiException('Notification recipient must be an active administrator'); }
            }
            $db->prepare("INSERT INTO system_settings(`key`,`group`,value,encrypted_credentials,updated_by) VALUES('notify.mail','notification',?,?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value),encrypted_credentials=VALUES(encrypted_credentials),updated_by=VALUES(updated_by)")
                ->execute([json_encode($public,JSON_THROW_ON_ERROR),$encrypted,$admin]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'notification_configure','notification',0,?,?)")
                ->execute([$admin,json_encode($public,JSON_THROW_ON_ERROR),Security::getClientIp()]);
        });
    }

    public static function authorize(PDO $db,int $admin):void
    {
        $s=$db->prepare("SELECT id FROM admins WHERE id=? AND role='super_admin' AND status='active' FOR UPDATE");$s->execute([$admin]);
        if (!$s->fetchColumn()) { throw new ApiException('Active super_admin required',403,403); }
    }

    /** An explicit commit starts a new subscription period; never sweep historical ticket messages. */
    public function configureTickets(int $admin,array $input): array
    {
        if(array_diff(array_keys($input),['enabled','admin_ids']) || !is_bool($input['enabled']??null)
            || Validator::make($input,['admin_ids'=>'present|array|max:20','admin_ids.*'=>'required|integer|min:1|distinct'])->fails()
            || ($input['enabled'] && !$input['admin_ids'])){throw new ApiException('Explicit ticket mail switch and administrator recipients required');}
        $recipients=array_map('intval',array_values($input['admin_ids']));sort($recipients);
        return Database::transaction(static function(PDO $db)use($admin,$input,$recipients):array{
            self::authorize($db,$admin);
            foreach($recipients as $id){
                $s=$db->prepare("SELECT email FROM admins WHERE id=? AND status='active' FOR UPDATE");$s->execute([$id]);
                if(!filter_var($s->fetchColumn(),FILTER_VALIDATE_EMAIL)){throw new ApiException('Ticket recipient must be an active administrator');}
            }
            $s=$db->query("SELECT value FROM system_settings WHERE `key`='notify.mail' FOR UPDATE");$row=$s->fetchColumn();
            if($input['enabled'] && (!$row || (json_decode($row,true,32,JSON_THROW_ON_ERROR)['enabled']??false)!==true)){
                throw new ApiException('Configure and enable the mail channel before ticket subscriptions');
            }
            $value=['enabled'=>$input['enabled'],'admin_ids'=>$recipients,'activation'=>bin2hex(random_bytes(16)),
                'activated_at'=>$db->query('SELECT UTC_TIMESTAMP()')->fetchColumn()];
            $db->prepare("INSERT INTO system_settings(`key`,`group`,value,updated_by) VALUES('notify.ticket_mail','notification',?,?)
                ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by=VALUES(updated_by)")->execute([json_encode($value,JSON_THROW_ON_ERROR),$admin]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'ticket_notification_configure','notification',0,?,?)")
                ->execute([$admin,json_encode($value,JSON_THROW_ON_ERROR),Security::getClientIp()]);
            return $value;
        },3);
    }

    public function load(): ?array
    {
        $s=Database::getInstance()->query("SELECT value,encrypted_credentials FROM system_settings WHERE `key`='notify.mail'");$row=$s->fetch();
        if (!$row) { return null; }
        $value=json_decode($row['value'],true,32,JSON_THROW_ON_ERROR);
        if (($value['enabled']??false)!==true) { return null; }
        $plain=Security::decrypt($row['encrypted_credentials']??'');
        if ($plain===null) { throw new \RuntimeException('Mail configuration unavailable'); }
        return $value+['config'=>json_decode($plain,true,32,JSON_THROW_ON_ERROR)];
    }
}
