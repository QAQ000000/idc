<?php
declare(strict_types=1);
namespace App\Plugins\Providers;
use App\Core\ApiException;

final class ConfigOptions
{
    /** Canonical IDs/values make equivalent JSON objects share the checkout identity. */
    public static function canonical(array $options): array
    {
        $result = [];
        foreach ($options as $id => $value) {
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', (string)$id) || (!is_string($value) && !is_int($value))
                || !preg_match('/^[0-9]{1,10}$/D', (string)$value)) { throw new ApiException('Invalid configuration IDs or values'); }
            $result[(int)$id] = (string)(int)$value;
        }
        ksort($result, SORT_NUMERIC);
        return $result;
    }
    public static function validate(array $form, array $options): array
    {
        $options = self::canonical($options);
        $expected = [];
        foreach ($form as $field) {
            $id = $field['id']; $expected[] = (int)$id;
            if (!isset($options[$id]) || !in_array($options[$id], array_column(self::available($field,$options), 'value'), true)) {
                throw new ApiException('Select a valid value for ' . $field['label']);
            }
        }
        sort($expected, SORT_NUMERIC);
        if (array_keys($options) !== $expected) { throw new ApiException('Unexpected configuration option'); }
        return $options;
    }
    /** Active allow rules union together, then active exclusions subtract. No match means unrestricted. */
    public static function available(array $field, array $options): array
    {
        $allowed=null;$excluded=[];
        foreach($field['dependencies']??[] as $rule){
            $value=$options[$rule['field']]??null;
            // An unselected parent must not make a negative condition fire.
            if($value===null || $value===''){return [];}
            $matches=in_array((string)$value,$rule['values'],true);
            if($matches!==($rule['operator']==='in'))continue;
            if($rule['effect']==='allow'){$allowed=array_merge($allowed??[],$rule['choices']);}
            else{$excluded=array_merge($excluded,$rule['choices']);}
        }
        return array_values(array_filter($field['choices'],static fn(array $c):bool=>($allowed===null || in_array($c['value'],$allowed,true)) && !in_array($c['value'],$excluded,true)));
    }
    /** Drivers project dependencies in parent-first order; defaults never ignore a rule. */
    public static function defaults(array $form): array
    {
        $options=[];
        foreach($form as $field){
            $choices=self::available($field,$options);
            if(!$choices){throw new ApiException('No valid default for '.$field['label']);}
            $values=array_column($choices,'value');$options[$field['id']]=in_array($field['default'],$values,true)?$field['default']:$values[0];
        }
        return self::canonical($options);
    }
    public static function hash(array $form): string
    {
        return hash('sha256', json_encode($form, JSON_THROW_ON_ERROR));
    }
}
