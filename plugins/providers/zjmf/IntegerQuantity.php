<?php
declare(strict_types=1);
namespace Plugins\providers\zjmf;

/** ZJMF types 15/18 submit a quantity, never the pricing sub-option ID. */
final class IntegerQuantity
{
    public static function project(array $option, string $label): array
    {
        $integer = static function(mixed $value): int {
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]{1,10}$/D', (string)$value)) {
                throw new \RuntimeException('Invalid integer quantity schema');
            }
            return (int)$value;
        };
        $min=$integer($option['qty_minimum']??null); $max=$integer($option['qty_maximum']??null);
        $step=max(1,$integer($option['qty_stage']??null));
        if ($min>$max || !is_array($option['sub']??null) || !$option['sub'] || count($option['sub'])>500) {
            throw new \RuntimeException('Invalid quantity bounds');
        }
        $ranges=[];
        foreach ($option['sub'] as $sub) {
            $id=(string)($sub['id']??'');
            if (!preg_match('/^[1-9][0-9]{0,9}$/D',$id) || isset($ranges[$id]) || !empty($sub['hidden'])) {
                throw new \RuntimeException('Invalid or hidden quantity interval');
            }
            $lo=$integer($sub['qty_minimum']??null); $hi=$integer($sub['qty_maximum']??null);
            if ($lo>$hi || $lo<$min || $hi>$max) { throw new \RuntimeException('Invalid quantity interval'); }
            $ranges[$id]=['id'=>$id,'minimum'=>$lo,'maximum'=>$hi];
        }
        usort($ranges,static fn(array $a,array $b):int=>$a['minimum']<=>$b['minimum']);
        // Ambiguous or missing pricing intervals are not silently repaired or submitted upstream.
        $next=$min;
        foreach ($ranges as $range) {
            if ($range['minimum']!==$next) { throw new \RuntimeException('Overlapping or incomplete quantity intervals'); }
            $next=$range['maximum']+1;
        }
        if ($next!==$max+1) { throw new \RuntimeException('Incomplete quantity intervals'); }
        return ['id'=>(string)$option['id'],'type'=>'integer','label'=>$label,'choices'=>[], 'default'=>(string)$min,
            'minimum'=>$min,'maximum'=>$max,'step'=>$step,'step_base'=>0,'allow_endpoints'=>true,'ranges'=>array_values($ranges)];
    }
}
