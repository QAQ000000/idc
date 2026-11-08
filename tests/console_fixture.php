<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Workerman\Worker;
$worker=new Worker('websocket://127.0.0.1:'.getenv('CONSOLE_FIXTURE_PORT'));
Worker::$pidFile=getenv('CONSOLE_TEST_AUTH_FILE').'.fixture.pid';Worker::$logFile='/dev/null';Worker::$outputStream=fopen('/dev/null','w');
$worker->onWebSocketConnect=static function($c):void{$c->websocketType=\Workerman\Protocols\Websocket::BINARY_TYPE_ARRAYBUFFER;$c->headers=['Sec-WebSocket-Protocol: binary'];};
$worker->onWebSocketConnected=static function($c):void{$c->context->phase=0;$c->send('RFB 003.');$c->send("008\n");};
$worker->onMessage=static function($c,$data):void{
 switch($c->context->phase++){
 case 0:if($data!=="RFB 003.008\n"){$c->destroy();return;}$c->send("\x01\x02");break;
 case 1:if($data!=="\x02"){$c->destroy();return;}$c->send(hex2bin('000102030405060708090a0b0c0d0e0f'));break;
 case 2:if(bin2hex($data)!=='b866924125c8eebb9debc1db61c538e2'){$c->destroy();return;}$c->send("\0\0\0\0");break;
 case 3:if($data!=="\x01"){$c->destroy();return;}$name='NEVER-EXPOSE-supplier.example';$pixel=hex2bin('2018000100ff00ff00ff100800000000');$c->send(pack('nn',2,2).$pixel.pack('N',strlen($name)).$name);break;
 default:$c->send($data);
 }
};
Worker::runAll();
