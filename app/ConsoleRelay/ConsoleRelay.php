<?php
declare(strict_types=1);
namespace App\ConsoleRelay;

use App\Services\InstanceConsoleService;
use Workerman\{Worker, Timer};
use Workerman\Connection\{TcpConnection, AsyncTcpConnection};
use Workerman\Protocols\Http\Request;

final class ConsoleRelay
{
    public static function run(): void
    {
        $listen=config('console.listen');
        if(!preg_match('/^127\.0\.0\.1:([0-9]{4,5})$/D',$listen)){throw new \RuntimeException('Console must listen on loopback');}
        if(!config('console.origins') || !config('console.targets')){throw new \RuntimeException('Console origins and targets required');}
        // Vendor protocol diagnostics can include raw frames/URLs; never send these to a log sink.
        Worker::$outputStream=fopen('/dev/null','w');Worker::$logFile='/dev/null';
        Worker::$pidFile=config('console.pid_file');Worker::$statusFile='/dev/null';
        TcpConnection::$defaultMaxPackageSize=4194304;TcpConnection::$defaultMaxSendBufferSize=8388608;
        $worker=new Worker('websocket://'.$listen);$worker->count=1;$worker->name='jianyun-console';
        $service=new InstanceConsoleService();$active=[];
        $worker->onConnect=static function(TcpConnection $c) use($worker):void{
            if(count($worker->connections)>32){$c->destroy();return;}
            $c->websocketType=\Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;
            $c->context->started=time();$c->context->accepted=false;
            $c->context->deadline=Timer::add(20,static fn()=>$c->destroy(),[],false);
            $c->onBufferFull=static fn()=>$c->destroy();
            $c->onError=static fn()=>$c->destroy();
        };
        $worker->onWebSocketConnect=static function(TcpConnection $c,Request $req) use($service,&$active):void{
            try{
                if($req->method()!=='GET' || !preg_match('#^/console/ws/([a-f0-9]{64})$#D',$req->uri(),$m)){throw new \RuntimeException();}
                $protocols=array_map('trim',explode(',',$req->header('sec-websocket-protocol','')));
                if(count($protocols)!==2 || $protocols[0]!=='binary' || !str_starts_with($protocols[1],'jianyun.') || strlen($protocols[1])>4096){throw new \RuntimeException();}
                $token=substr($protocols[1],8);$r=$service->consume($token,$m[1],$req->header('origin',''));
                if(isset($active[$r['instance']])){throw new \RuntimeException();}
                $p=TargetPolicy::resolve($r['url']);$active[$r['instance']]=$c->id;
                $c->context->record=$r;$c->context->token=$token;$c->context->target=$p;$c->context->accepted=true;
                $c->headers=['Sec-WebSocket-Protocol: binary','Cache-Control: no-store'];
            }catch(\Throwable){$c->close("HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n",true);}
        };
        $worker->onWebSocketConnected=static function(TcpConnection $c) use($service):void{
            if(!$c->context->accepted){$c->destroy();return;}
            try{
                $p=$c->context->target;$r=$c->context->record;
                $url='ws://'.$p['ip'].':'.$p['port'].$p['path'].(isset($p['query'])?'?'.$p['query']:'');
                $up=new AsyncTcpConnection($url,['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$p['host'],
                    'SNI_enabled'=>true,'disable_compression'=>true]]);
                if($p['scheme']==='wss'){$up->transport='ssl';}
                $up->headers=['Host'=>$p['host'].($p['port']===443?'':':'.$p['port'])];
                $up->websocketOrigin=$r['origin'];$up->websocketClientProtocol='binary';
                $up->websocketType=\Workerman\Protocols\Ws::BINARY_TYPE_ARRAYBUFFER;
                $c->context->up=$up;
                $bridge=new RfbBridge($r['password'],static fn(string $s)=>$up->send($s),static fn(string $s)=>$c->send($s));
                $c->context->bridge=$bridge;
                $up->onMessage=static function(AsyncTcpConnection $u,string $s) use($c,$bridge):void{
                    try{$bridge->upstream($s);if($bridge->ready() && isset($c->context->deadline)){Timer::del($c->context->deadline);unset($c->context->deadline);}}
                    catch(\Throwable){fwrite(STDERR,"console: protocol rejected\n");$c->destroy();}
                };
                $up->onError=static function() use($c):void{fwrite(STDERR,"console: upstream unavailable\n");$c->destroy();};
                $up->onClose=static fn()=>$c->destroy();$up->onBufferFull=static fn()=>$c->destroy();
                $c->context->monitor=Timer::add(15,static function() use($c,$service):void{
                    try{if(time()-$c->context->started>=900){throw new \RuntimeException();}$service->revalidate($c->context->token,$c->context->record);}
                    catch(\Throwable){$c->destroy();}
                });
                // Exact expiry, independently of periodic ownership/account/task revalidation.
                $claims=\App\Core\Security::verifyToken($c->context->token);
                $c->context->expiry=Timer::add(max(0.001,min(900,$claims['exp']-time())),static fn()=>$c->destroy(),[],false);
                $up->connect();
            }catch(\Throwable){fwrite(STDERR,"console: connection rejected\n");$c->destroy();}
        };
        $worker->onMessage=static function(TcpConnection $c,string $s):void{
            try{if(!$c->context->accepted || !isset($c->context->bridge)){throw new \RuntimeException();}$c->context->bridge->client($s);}
            catch(\Throwable){$c->destroy();}
        };
        $worker->onClose=static function(TcpConnection $c) use(&$active):void{
            foreach(['deadline','monitor','expiry'] as $key){if(isset($c->context->$key)){Timer::del($c->context->$key);}}
            if(isset($c->context->record) && ($active[$c->context->record['instance']]??null)===$c->id){unset($active[$c->context->record['instance']]);}
            if(isset($c->context->up)){$c->context->up->onClose=null;$c->context->up->destroy();}
            unset($c->context->bridge,$c->context->up,$c->context->record,$c->context->token,$c->context->target);
        };
        fwrite(STDERR,"console: relay starting on loopback\n");Worker::runAll();
    }
}
