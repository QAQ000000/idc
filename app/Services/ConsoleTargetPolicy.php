<?php
declare(strict_types=1);
namespace App\Services;

final class ConsoleTargetPolicy
{
    private static function parse(string $url): array
    {
        if (strlen($url)>8192 || preg_match('/[\x00-\x20\x7f]/',$url)) { throw new \RuntimeException('CONSOLE_TARGET'); }
        $p=parse_url($url);
        if (!$p || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])
            || !in_array($p['scheme']??'',config('console.test_loopback')?['ws','wss']:['wss'],true)
            || !preg_match('/^[a-zA-Z0-9.-]+$/D',$p['host']??'') || ($p['path']??'/')[0]!=='/') { throw new \RuntimeException('CONSOLE_TARGET'); }
        $port=$p['port']??443;
        if (!in_array(strtolower($p['host']).':'.$port,config('console.targets'),true)) { throw new \RuntimeException('CONSOLE_TARGET'); }
        return $p+['port'=>$port,'path'=>'/'];
    }

    /** Reject private destinations before returning a browser-visible target. Browser resolves DNS itself. */
    public static function validate(string $url): array
    {
        $p=self::parse($url);$host=$p['host'];
        $ips=filter_var($host,FILTER_VALIDATE_IP)?[$host]:gethostbynamel($host);
        if (!$ips) { throw new \RuntimeException('CONSOLE_DNS'); }
        foreach ($ips as $ip) {
            $public=filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
            if (!$public && !(config('console.test_loopback') && $ip==='127.0.0.1')) { throw new \RuntimeException('CONSOLE_ADDRESS'); }
        }
        return $p+['ip'=>$ips[0]];
    }
}
