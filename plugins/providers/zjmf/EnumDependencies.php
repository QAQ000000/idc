<?php

declare(strict_types=1);

namespace Plugins\providers\zjmf;

use App\Plugins\Providers\ConfigOptions;

/** Only flat enum conditions/results; independent integer fields can coexist. */
final class EnumDependencies
{
    public static function project(array $fields, mixed $advanced): array
    {
        if (! is_array($advanced) || ! array_is_list($advanced) || count($advanced) > 500) {
            throw new \RuntimeException('Invalid advanced rules');
        }
        if (! $advanced) {
            return array_values($fields);
        } // Preserve historical independent form hashes.
        $rows = [];
        $conditions = [];
        $used = [];
        $identity = static function (mixed $id): string {
            if ((! is_int($id) && ! is_string($id)) || ! preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)) {
                throw new \RuntimeException('Invalid dependency identity');
            }

            return (string) $id;
        };
        foreach ($advanced as $row) {
            if (! is_array($row)) {
                throw new \RuntimeException('Invalid dependency row');
            }
            $id = $identity($row['id'] ?? null);
            $field = $identity($row['config_id'] ?? null);
            if (isset($rows[$id]) || ! isset($fields[$field]) || $fields[$field]['type'] !== 'select' || ! in_array($row['type'] ?? null, ['condition', 'result'], true)
                || ! in_array($row['relation'] ?? null, ['seq', 'sneq'], true) || ! is_array($row['sub_id'] ?? null) || ! $row['sub_id']) {
                throw new \RuntimeException('Unsupported dependency');
            }
            $values = [];
            foreach ($row['sub_id'] as $value => $range) {
                $value = $identity($value);
                if (! in_array($value, array_column($fields[$field]['choices'], 'value'), true) || ! is_array($range)
                    || array_diff(array_keys($range), ['qty_minimum', 'qty_maximum'])
                    || ! in_array($range['qty_minimum'] ?? null, [0, '0'], true) || ! in_array($range['qty_maximum'] ?? null, [0, '0'], true)) {
                    throw new \RuntimeException('Unknown enum or quantity dependency');
                }
                $values[] = $value;
            }
            sort($values, SORT_NUMERIC);
            $parent = $row['relation_id'] ?? null;
            if ($row['type'] === 'condition') {
                if (! in_array($parent, [0, '0'], true)) {
                    throw new \RuntimeException('Nested conditions unsupported');
                }
                $conditions[$id] = true;
            } else {
                $parent = $identity($parent);
            }
            $rows[$id] = ['field' => $field, 'operator' => $row['relation'] === 'seq' ? 'in' : 'not_in', 'values' => $values, 'type' => $row['type'], 'parent' => $parent];
        }
        // One source field per target: no ambiguous multi-parent merge or cyclic reset semantics.
        $parents = [];
        foreach ($rows as $row) {
            if ($row['type'] !== 'result')continue;
            $condition = $rows[$row['parent']] ?? null;
            if (! $condition || $condition['type'] !== 'condition' || $row['field'] === $condition['field']
                || (isset($parents[$row['field']]) && $parents[$row['field']] !== $condition['field'])) {
                throw new \RuntimeException('Orphan, self or multiple-parent dependency');
            }
            $used[$row['parent']] = true;
            $parents[$row['field']] = $condition['field'];
            $fields[$row['field']]['dependencies'][] = ['field' => $condition['field'], 'operator' => $condition['operator'],
                'values' => $condition['values'], 'effect' => $row['operator'] === 'in' ? 'allow' : 'exclude', 'choices' => $row['values']];
        }
        if (count($used) !== count($conditions)) {
            throw new \RuntimeException('Condition without result');
        }
        foreach ($fields as &$field) {
            if (isset($field['dependencies'])) {
                // Rule row IDs/order are not sales semantics; canonicalize equivalent upstream snapshots.
                $unique = [];
                foreach ($field['dependencies'] as $rule) {
                    $unique[json_encode($rule, JSON_THROW_ON_ERROR)] = $rule;
                }
                ksort($unique, SORT_STRING);
                $field['dependencies'] = array_values($unique);
            }
        }unset($field);
        $ordered = [];
        $visiting = [];
        $visit = function (string $id) use (&$visit, &$ordered, &$visiting, $fields, $parents): void {
            if (isset($ordered[$id]))return;
            if (isset($visiting[$id])) {
                throw new \RuntimeException('Cyclic enum dependency');
            }
            $visiting[$id] = true;
            if (isset($parents[$id]))$visit($parents[$id]);
            $ordered[$id] = $fields[$id];
            unset($visiting[$id]);
        };
        foreach (array_keys($fields) as $id) {
            $visit((string) $id);
        }
        $defaults = ConfigOptions::defaults(array_values($ordered));
        foreach ($ordered as &$field) {
            $field['default'] = $defaults[$field['id']];
        }unset($field);

        return array_values($ordered);
    }
}
