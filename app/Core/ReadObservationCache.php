<?php
declare(strict_types=1);
namespace App\Core;

/** Disposable read cache and single-flight only. Never authorizes an operation or releases a task. */
final class ReadObservationCache
{
    public static function key(string $scope): string
    {
        return 'jianyun:display:'.Security::requestFingerprint(config('database.connections.mysql.database').'|'.$scope);
    }
    public function read(string $key): ?array
    {
        try { return $this->decode((new RedisStore())->command('GET',$key)); }
        catch (\Throwable) { return null; }
    }
    private function decode(mixed $raw): ?array
    {
        if (!is_string($raw)) { return null; }
        try {
            $v=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            return is_array($v) && is_string($v['generation']??null) && is_int($v['expires']??null)
                && $v['expires']>time() && is_array($v['value']??null) ? $v : null;
        } catch (\Throwable) { return null; }
    }
    public function remember(string $key,int $ttl,callable $load,bool $force=false): array
    {
        $redis=new RedisStore();$owner=bin2hex(random_bytes(16));
        try {
            $before=$this->decode($redis->command('GET',$key));
            if (!$force && $before) { return $before['value']; }
            $acquired=$redis->command('SET',$key.':lock',$owner,'NX','EX','60');
        } catch (\Throwable) { return $load(); } // Cache failure does not retry the loader.
        if (!$acquired) {
            $deadline=microtime(true)+42;
            do {
                usleep(100000);
                $current=$this->read($key);
                if ($current && ($current['generation']!==($before['generation']??null))) { return $current['value']; }
                try { $locked=$redis->command('GET',$key.':lock'); }
                catch (\Throwable) { throw new \RuntimeException('Shared observation unavailable'); }
                if (!$locked) { throw new \RuntimeException('Shared observation failed; try again later'); }
            } while (microtime(true)<$deadline);
            throw new \RuntimeException('Shared observation timed out');
        }
        try {
            $value=$load();
            $raw=json_encode(['generation'=>$owner,'expires'=>time()+$ttl,'value'=>$value],JSON_THROW_ON_ERROR);
            // An expired owner must never publish over a newer observation.
            try { $redis->command('EVAL',"if redis.call('GET',KEYS[2]) == ARGV[1] then redis.call('SET',KEYS[1],ARGV[2],'EX',ARGV[3]); return 1 else return 0 end",'2',$key,$key.':lock',$owner,$raw,(string)$ttl); }
            catch (\Throwable) { /* A successful read is still useful without a cache. */ }
            return $value;
        } finally {
            try { $redis->command('EVAL',"if redis.call('GET',KEYS[1]) == ARGV[1] then return redis.call('DEL',KEYS[1]) else return 0 end",'1',$key.':lock',$owner); }
            catch (\Throwable) {}
        }
    }
}
