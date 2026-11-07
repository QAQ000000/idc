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
            if (!isset($options[$id]) || !in_array($options[$id], array_column($field['choices'], 'value'), true)) {
                throw new ApiException('Select a valid value for ' . $field['label']);
            }
        }
        sort($expected, SORT_NUMERIC);
        if (array_keys($options) !== $expected) { throw new ApiException('Unexpected configuration option'); }
        return $options;
    }
    public static function hash(array $form): string
    {
        return hash('sha256', json_encode($form, JSON_THROW_ON_ERROR));
    }
}
