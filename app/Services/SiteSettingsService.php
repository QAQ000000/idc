<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\{ApiException, Database, Request, Security};
use PDO;

/** Public branding only. Never enumerate settings or decrypt credentials. */
final class SiteSettingsService
{
    private const DEFAULTS = ['site_name'=>'简云','logo_path'=>'','company_name'=>'','copyright_text'=>'让云资源管理更简单','icp_number'=>''];
    private const LIMITS = ['site_name'=>80,'logo_path'=>255,'company_name'=>120,'copyright_text'=>200,'icp_number'=>80];

    private function authorize(PDO $db, int $actor, int $session): void
    {
        $s=$db->prepare('SELECT status,role,two_factor_secret FROM admins WHERE id=? FOR UPDATE');$s->execute([$actor]);$a=$s->fetch();
        if(!$a||$a['status']!=='active'||!empty($a['two_factor_secret'])||AdminAuthService::sessionVersion($db,$actor,true)!==$session){throw new ApiException('Administrator session revoked; log in again',401,401);}
        if($a['role']!=='super_admin'){throw new ApiException('Active super_admin required',403,403);}
    }

    private function row(PDO $db, bool $lock=false): ?array
    {
        $s=$db->prepare("SELECT value FROM system_settings WHERE `key`='site.basic'".($lock?' FOR UPDATE':''));$s->execute();return $s->fetch()?:null;
    }

    private function valid(string $field, mixed $value): bool
    {
        if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||mb_strlen($value,'UTF-8')>self::LIMITS[$field]||preg_match('/[\x00-\x1f\x7f]/',$value)){return false;}
        // No external URL, API address, traversal, encoded separator, query or fragment. No server-side fetching/upload.
        return $field!=='logo_path'||$value===''||(bool)preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:png|jpe?g|gif|webp|svg|ico)$~D',$value);
    }

    private function projection(?array $row): array
    {
        $raw=$row?json_decode($row['value'],true):[];$safe=[];
        foreach(self::DEFAULTS as $field=>$default){$value=is_array($raw)?($raw[$field]??null):null;$safe[$field]=$this->valid($field,$value)?trim($value):$default;}
        return $safe;
    }

    private function version(PDO $db, ?array $row): string
    {
        $audit=(int)($db->query("SELECT id FROM admin_audit_logs WHERE target_type='site' AND target_id=0 AND action='site_configure' ORDER BY id DESC LIMIT 1 FOR UPDATE")->fetchColumn()?:0);
        return Security::requestFingerprint(json_encode([$row,$audit],JSON_THROW_ON_ERROR));
    }

    public function publicConfig(): array
    {
        return $this->projection($this->row(Database::getInstance()));
    }

    public function show(int $actor, int $session): array
    {
        return Database::transaction(function(PDO $db)use($actor,$session):array{
            $this->authorize($db,$actor,$session);$row=$this->row($db,true);
            return $this->projection($row)+['configured'=>$row!==null,'version'=>$this->version($db,$row)];
        },3);
    }

    public function save(int $actor, int $session, array $input): void
    {
        Database::transaction(function(PDO $db)use($actor,$session,$input):void{
            $this->authorize($db,$actor,$session);
            if(array_diff(array_keys($input),[...array_keys(self::DEFAULTS),'expected_version'])||array_diff(array_keys(self::DEFAULTS),array_keys($input))){throw new ApiException('Unsupported or missing site field');}
            $public=[];
            foreach(self::DEFAULTS as $field=>$default){if(!$this->valid($field,$input[$field])){throw new ApiException('Invalid '.$field);}$public[$field]=trim($input[$field]);}
            $row=$this->row($db,true);
            if(!hash_equals($this->version($db,$row),Request::text($input,'expected_version',64,64))){throw new ApiException('Site configuration changed; reload and review again',409,409);}
            $db->prepare("INSERT INTO system_settings(`key`,`group`,value,updated_by) VALUES('site.basic','site',?,?) ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by=VALUES(updated_by)")
                ->execute([json_encode($public,JSON_THROW_ON_ERROR),$actor]);
            $db->prepare("INSERT INTO admin_audit_logs(admin_id,action,target_type,target_id,context,ip) VALUES(?,'site_configure','site',0,?,?)")
                ->execute([$actor,json_encode(['before'=>$this->projection($row),'after'=>$public],JSON_THROW_ON_ERROR),Security::getClientIp()]);
        },5);
    }
}
