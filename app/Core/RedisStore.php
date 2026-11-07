<?php
declare(strict_types=1);
namespace App\Core;

/** 小型 RESP2 客户端，仅用于短验证码；不依赖第三方扩展。 */
final class RedisStore
{
    public function command(string ...$args): mixed
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 2);
        if (!$socket) {
            throw new \RuntimeException('Redis unavailable');
        }
        stream_set_timeout($socket, 2);
        try {
            $password = getenv('REDIS_PASSWORD');
            if ($password !== false && $password !== '' && $password !== 'null') {
                $this->send($socket, ['AUTH', $password]);
                $this->read($socket);
            }
            $this->send($socket, $args);
            return $this->read($socket);
        } finally {
            fclose($socket);
        }
    }

    private function send($socket, array $args): void
    {
        $wire = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $wire .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        while ($wire !== '') {
            $written = fwrite($socket, $wire);
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Redis write failed');
            }
            $wire = substr($wire, $written);
        }
    }

    private function read($socket): mixed
    {
        $line = fgets($socket);
        if ($line === false) {
            throw new \RuntimeException('Redis read failed');
        }
        $value = substr($line, 1, -2);
        switch ($line[0]) {
            case '+': return $value;
            case ':': return (int)$value;
            case '$':
                $length = (int)$value;
                if ($length === -1) {
                    return null;
                }
                if ($length < 0 || $length > 65536) {
                    throw new \RuntimeException('Unexpected Redis response size');
                }
                $data = '';
                while (strlen($data) < $length + 2) {
                    $part = fread($socket, $length + 2 - strlen($data));
                    if ($part === false || $part === '') {
                        throw new \RuntimeException('Incomplete Redis response');
                    }
                    $data .= $part;
                }
                return substr($data, 0, $length);
            default: throw new \RuntimeException('Unexpected Redis response');
        }
    }
}
