<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
if(config('app.env')!=='test'||config('database.connections.mysql.database')!=='jianyun_test'){throw new RuntimeException('Isolated DB only');}
use App\Core\{Database,Security};
$db=Database::getInstance();$mode=$argv[1]??'';
if($mode==='seed'){
    $db->prepare('INSERT INTO providers(name,driver,endpoint,credentials) VALUES(?,?,?,?)')->execute(['Runtime refresh fixture','zjmf',getenv('UPSTREAM_TEST_URL'),json_encode(['username'=>Security::encrypt('fixture'),'password'=>Security::encrypt('fixture')])]);
    $pid=(new App\Services\ProductSnapshotService())->save(['provider_id'=>(int)$db->lastInsertId(),'remote_pid'=>389,'name'=>'Runtime-only snapshot','type'=>'cloud','markup_type'=>'none','markup_value'=>'0.00'])['product_id'];
    echo json_encode(['product_id'=>$pid]),PHP_EOL;
}elseif(in_array($mode,['inspect','due'],true)){
    $id=(int)($argv[2]??0);$s=$db->prepare('SELECT value FROM system_settings WHERE `key`=?');$s->execute(['product.refresh.'.$id]);$meta=json_decode($s->fetchColumn()?:'{}',true);
    if($mode==='due'){$meta['last_attempt_at']=time()-601;$db->prepare("INSERT INTO system_settings(`key`,`group`,value) VALUES(?,'catalog',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute(['product.refresh.'.$id,json_encode($meta)]);}
    $s=$db->prepare('SELECT config_cache FROM products WHERE id=?');$s->execute([$id]);echo json_encode(['meta'=>(object)$meta,'hash'=>hash('sha256',$s->fetchColumn())]),PHP_EOL;
}else{throw new RuntimeException('Invalid mode');}
