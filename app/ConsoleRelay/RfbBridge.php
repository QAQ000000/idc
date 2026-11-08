<?php
declare(strict_types=1);
namespace App\ConsoleRelay;

/** Separate authentication on each side; supplier passwords never enter the browser RFB stream. */
final class RfbBridge
{
    private string $up='';
    private string $down='';
    private string $phase='version';
    private string $clientPhase='waiting';
    private bool $initialized=false;
    public function __construct(private string $password, private \Closure $toUp, private \Closure $toClient) {}
    public function ready(): bool { return $this->initialized; }
    public static function challenge(string $challenge,string $password): string
    {
        if (strlen($challenge)!==16) { throw new \RuntimeException('RFB_CHALLENGE'); }
        $key='';$bytes=str_pad(substr($password,0,8),8,"\0");
        for($i=0;$i<8;$i++){$v=ord($bytes[$i]);$r=0;for($j=0;$j<8;$j++){$r=($r<<1)|(($v>>$j)&1);}$key.=chr($r);}
        // EDE(K,K,K) is DES(K); modern OpenSSL disables single DES but supports this equivalent.
        $out=openssl_encrypt($challenge,'des-ede3-ecb',str_repeat($key,3),OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING);
        if ($out===false) { throw new \RuntimeException('RFB_CRYPTO'); }return $out;
    }
    public function upstream(string $data): void
    {
        if ($this->initialized) { ($this->toClient)($data);return; }
        $this->up.=$data;if(strlen($this->up)>65536){throw new \RuntimeException('RFB_LIMIT');}
        while(true){
            if($this->phase==='version'){
                if(strlen($this->up)<12){return;}
                if($this->take($this->up,12)!=="RFB 003.008\n"){throw new \RuntimeException('RFB_VERSION');}
                ($this->toUp)("RFB 003.008\n");$this->phase='types';
            }elseif($this->phase==='types'){
                if(strlen($this->up)<1){return;}$n=ord($this->up[0]);if(!$n){throw new \RuntimeException('RFB_SECURITY');}
                if(strlen($this->up)<$n+1){return;}$types=$this->take($this->up,$n+1);
                if(str_contains(substr($types,1),"\x02")){($this->toUp)("\x02");$this->phase='challenge';}
                elseif(str_contains(substr($types,1),"\x01")){($this->toUp)("\x01");$this->phase='result';}
                else{throw new \RuntimeException('RFB_SECURITY');}
            }elseif($this->phase==='challenge'){
                if(strlen($this->up)<16){return;}($this->toUp)(self::challenge($this->take($this->up,16),$this->password));$this->password='';$this->phase='result';
            }elseif($this->phase==='result'){
                if(strlen($this->up)<4){return;}if($this->take($this->up,4)!=="\0\0\0\0"){throw new \RuntimeException('RFB_AUTH');}
                $this->password='';$this->phase='client';$this->clientPhase='version';($this->toClient)("RFB 003.008\n");
            }elseif($this->phase==='init'){
                if(strlen($this->up)<24){return;}$n=unpack('N',substr($this->up,20,4))[1];if($n>32768){throw new \RuntimeException('RFB_NAME');}
                if(strlen($this->up)<24+$n){return;}$head=$this->take($this->up,20);$this->take($this->up,4+$n);
                $name='JianYun Console';($this->toClient)($head.pack('N',strlen($name)).$name.$this->up);$this->up='';$this->initialized=true;return;
            }else{return;}
        }
    }
    public function client(string $data): void
    {
        if($this->clientPhase==='ready'){($this->toUp)($data);return;}
        $this->down.=$data;if(strlen($this->down)>64){throw new \RuntimeException('RFB_CLIENT');}
        while(true){
            if($this->clientPhase==='version'){
                if(strlen($this->down)<12){return;}
                if($this->take($this->down,12)!=="RFB 003.008\n"){throw new \RuntimeException('RFB_CLIENT_VERSION');}
                $this->clientPhase='security';($this->toClient)("\x01\x01");
            }elseif($this->clientPhase==='security'){
                if(strlen($this->down)<1){return;}if($this->take($this->down,1)!=="\x01"){throw new \RuntimeException('RFB_CLIENT_SECURITY');}
                $this->clientPhase='init';($this->toClient)("\0\0\0\0");
            }elseif($this->clientPhase==='init'){
                if(strlen($this->down)<1){return;}$shared=$this->take($this->down,1);
                if($shared!=="\x01"){throw new \RuntimeException('RFB_SHARED');}
                $this->clientPhase='ready';$this->phase='init';($this->toUp)($shared.$this->down);$this->down='';return;
            }else{throw new \RuntimeException('RFB_EARLY_CLIENT');}
        }
    }
    private function take(string &$buffer,int $length): string
    { $out=substr($buffer,0,$length);$buffer=substr($buffer,$length);return $out; }
}
