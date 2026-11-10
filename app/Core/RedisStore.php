<?php

declare(strict_types=1);

namespace App\Core;

use Illuminate\Support\Facades\Redis;

/** 保留插件调用面，连接、认证与协议由成熟客户端承接。 */
final class RedisStore
{
    public function command(string ...$args): mixed
    {
        $result = Redis::connection()->client()->rawCommand(...$args);

        return $result === false ? null : $result;
    }
}
