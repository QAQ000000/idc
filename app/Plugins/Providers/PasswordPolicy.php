<?php
declare(strict_types=1);
namespace App\Plugins\Providers;
use App\Core\ApiException;

/** Optional preflight context, shared by checkout and the driver before submission. */
final class PasswordPolicy
{
    public const CHARACTER_SETS = ['upper'=>'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'lower'=>'abcdefghijklmnopqrstuvwxyz',
        'digit'=>'0123456789', 'special'=>'~@#$&*(){}[]|'];
    public static function bounds(array $context): array
    {
        $policy = $context['password_policy'] ?? ['min_length'=>12, 'max_length'=>72];
        if (!is_array($policy) || !is_int($policy['min_length'] ?? null) || !is_int($policy['max_length'] ?? null)
            || $policy['min_length'] < 12 || $policy['max_length'] > 72 || $policy['min_length'] > $policy['max_length']) {
            throw new ApiException('Password policy unavailable; operator review required',409,409);
        }
        $result = ['min_length'=>$policy['min_length'], 'max_length'=>$policy['max_length']];
        if (isset($policy['character_sets'])) {
            $sets = $policy['character_sets']; $length = $policy['generation_length'] ?? null;
            if (!is_array($sets) || !array_is_list($sets) || !$sets || count(array_unique($sets))!==count($sets)
                || array_diff($sets,array_keys(self::CHARACTER_SETS)) || !is_int($length)
                || $length<$policy['min_length'] || $length>$policy['max_length']) {
                throw new ApiException('Password generation rules unavailable; operator review required',409,409);
            }
            $result += ['character_sets'=>$sets,'generation_length'=>$length];
        }
        return $result;
    }
    public static function validate(string $password, array $context): void
    {
        $policy = self::bounds($context);
        if (strlen($password) < $policy['min_length'] || strlen($password) > $policy['max_length']) {
            throw new ApiException("初始密码长度须为 {$policy['min_length']}–{$policy['max_length']} 字节");
        }
        if (isset($policy['character_sets'])) {
            $allowed = '';
            foreach ($policy['character_sets'] as $name) {
                $characters = self::CHARACTER_SETS[$name]; $allowed .= $characters;
                if (strpbrk($password,$characters)===false) { throw new ApiException('初始密码须包含上游要求的每类字符，请按上游规则生成'); }
            }
            if (strspn($password,$allowed)!==strlen($password)) { throw new ApiException('初始密码包含上游生成规则以外的字符，请按上游规则生成'); }
        }
    }
}
