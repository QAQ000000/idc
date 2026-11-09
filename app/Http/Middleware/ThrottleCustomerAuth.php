<?php
declare(strict_types=1);
namespace App\Http\Middleware;

use App\Core\{ApiException, RedisStore, Security};
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public HTTP boundary only; independent of captcha and CLI account provisioning. */
final class ThrottleCustomerAuth
{
    public const LIMITS = ['login'=>10,'register'=>5];
    public function handle(Request $request, Closure $next, string $scene): Response
    {
        if(!isset(self::LIMITS[$scene])){throw new \LogicException('Unsupported authentication limit');}
        $key='jianyun:auth:'.$scene.':'.Security::requestFingerprint(Security::getClientIp());
        // Atomic fixed 60-second window, including malformed requests and concurrent arrivals.
        // Redis errors propagate: disabling captcha never converts an unavailable limiter into admission.
        $count=(new RedisStore())->command('EVAL',"local n=redis.call('INCR',KEYS[1]); if n==1 then redis.call('EXPIRE',KEYS[1],60) end; return n",'1',$key);
        if(!is_int($count)||$count<1){throw new \RuntimeException('Authentication limiter unavailable');}
        if($count>self::LIMITS[$scene]){throw new ApiException('请求过于频繁，请一分钟后再试',429,429);}
        return $next($request);
    }
}
