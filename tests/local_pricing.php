<?php
declare(strict_types=1);
// Pure pricing/CLI tests: no framework bootstrap, .env, database or real provider connection.
require dirname(__DIR__).'/vendor/autoload.php';
use App\Plugins\Providers\{ConfigOptions, PreflightResult};
use Plugins\providers\zjmf\{LocalPriceCalculator, PriceComparison, ZjmfDriver};
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL LOCAL PRICING: '.$label); }
    $checks++; echo "PASS LOCAL PRICING: $label\n";
}
set_error_handler(static function (int $level, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $level, $file, $line);
});
$calculator = new LocalPriceCalculator(); $comparison = new PriceComparison(); $driver = new ZjmfDriver();
$now = 1800000000;
$samples = json_decode(file_get_contents(__DIR__.'/fixtures/zjmf-pricing-samples.json'), true, 64, JSON_THROW_ON_ERROR)['samples'];
function envelope(array $schema): array { global $now; return ['schema'=>$schema, 'fetched_at'=>$now, 'currency'=>['id'=>1,'code'=>'CNY']]; }
function options(array $schema): array { global $driver; return ConfigOptions::defaults($driver->describeForm($schema)); }
function expectPrice(array $schema, array $config, string $cycle, string $first, string $recurring, string $label): array {
    global $calculator, $now;
    $result = $calculator->evaluate(envelope($schema), $cycle, $config, $now);
    check($result['supported'] && $result['first']===$first && $result['recurring']===$recurring, $label.' '.json_encode($result));
    return $result;
}
foreach ($samples as $sample) {
    foreach ($sample['cases'] as $case) {
        $result = expectPrice($sample['schema'], $case['config_options'], 'monthly', $case['first'], $case['recurring'],
            'saved supplier product '.$sample['schema']['products']['id'].' '.$case['name']);
        $online = fn()=>new PreflightResult($case['first'], $case['config_options'], context:['schema_hash'=>$result['schema_hash']], recurringAmount:$case['recurring']);
        $c = $comparison->compare(envelope($sample['schema']), 'monthly', $case['config_options'], $now, $online);
        check($c['status']==='matched' && $c['authoritative']==='upstream', 'saved combination compares both amounts, upstream remains authoritative');
    }
}
// Golden synthetic boundaries derived from get_total/quantityStagePrice, not new supplier quotes.
$base = $samples[0]['schema']; $defaults = options($base);
$ipIndex = array_search(277296, array_column($base['config_groups'][0]['options'], 'id'), true);
$bwSchema = $samples[1]['schema']; $bwDefaults = options($bwSchema);
foreach ([['1','257.00'],['2','297.00'],['5','417.00']] as [$n,$price]) {
    $config=$defaults; $config[277296]=$n; expectPrice($base,$config,'monthly',$price,$price,'IP inclusive tier/end boundary '.$n);
}
foreach ([['50','299.00'],['60','399.00'],['1000','9799.00']] as [$n,$price]) {
    $config=$bwDefaults; $config[278145]=$n; expectPrice($bwSchema,$config,'monthly',$price,$price,'bandwidth step/end boundary '.$n);
}
$withFees=$base;
$withFees['product_pricings'][0]['msetupfee']='1.23';
$withFees['config_groups'][0]['options'][$ipIndex]['sub'][0]['pricings'][0]['msetupfee']='2.34';
$withFees['config_groups'][0]['options'][$ipIndex]['sub'][1]['pricings'][0]['msetupfee']='4.56';
expectPrice($withFees,$defaults,'monthly','260.57','257.00','base setup plus current first-tier setup excluded from renewal');
$two=$defaults;$two[277296]='2';
expectPrice($withFees,$two,'monthly','302.79','297.00','second tier setup replaces first tier setup, never cumulative setup');
$zero=$withFees;$zero['config_groups'][0]['options'][$ipIndex]['qty_minimum']=0;
$zero['config_groups'][0]['options'][$ipIndex]['sub'][0]['qty_minimum']=0;
$zeroOptions=$defaults;$zeroOptions[277296]='0';
expectPrice($zero,$zeroOptions,'monthly','228.23','227.00','zero units incur neither unit charge nor quantity setup');
// Enum setup, annual first/renewal separation, non-integer percentage and mixed BCMath stages.
$cpuIndex=array_search(277293,array_column($base['config_groups'][0]['options'],'id'),true);
$enumFees=$base;$enumFees['config_groups'][0]['options'][$cpuIndex]['sub'][0]['pricings'][0]['msetupfee']='0.77';
expectPrice($enumFees,$defaults,'monthly','257.77','257.00','selected enum setup charged once');
// Use uniform synthetic annual matrix to avoid asserting an unqueried real supplier annual price.
$annual=$base;$annual['product_pricings'][0]['annually']='12.00';$annual['product_pricings'][0]['asetupfee']='1.11';
foreach($annual['config_groups'][0]['options'] as &$option){foreach($option['sub'] as &$sub){$sub['pricings'][0]['annually']='0.00';$sub['pricings'][0]['asetupfee']='0.00';}unset($sub);}unset($option);
$annual['config_groups'][0]['options'][$ipIndex]['sub'][0]['pricings'][0]['annually']='30.00';
$annual['config_groups'][0]['options'][$ipIndex]['sub'][1]['pricings'][0]['annually']='40.00';
$annual['config_groups'][0]['options'][$ipIndex]['sub'][1]['pricings'][0]['asetupfee']='2.22';
expectPrice($annual,$two,'annually','85.33','82.00','annual cycle uses annual matrix and current tier annual setup');
$annual['products']['upstream_price_value']='112.35';
expectPrice($annual,$two,'annually','95.86','92.12','annual setup and recurring honor fractional multiplier independently');
$fraction=$withFees;$fraction['products']['upstream_price_value']='112.35';
expectPrice($fraction,$two,'monthly','340.18','333.67','fractional multiplier truncates first and renewal at distinct source stages');
$tiny=$annual;$tiny['product_pricings'][0]['monthly']='0.01';$tiny['product_pricings'][0]['msetupfee']='0.01';
foreach($tiny['config_groups'][0]['options'] as &$option){foreach($option['sub'] as &$sub){$sub['pricings'][0]['monthly']='0.00';$sub['pricings'][0]['msetupfee']='0.00';}unset($sub);}unset($option);
$tiny['products']['upstream_price_value']='150.50';
expectPrice($tiny,$defaults,'monthly','0.03','0.01','half-cent renewal is truncated by upstream, not rounded up');
$tiny['products']['upstream_price_value']='99.99';
$r=$calculator->evaluate(envelope($tiny),'monthly',$defaults,$now);
check(!$r['supported'] && !isset($r['first'],$r['recurring']),'sub-cent zero renewal falls back, never exposes a zero customer price');
$oldScale=bcscale(7);
expectPrice($fraction,$two,'monthly','340.18','333.67','result independent of caller BCMath scale');
check(bcscale()===7,'calculator does not mutate global BCMath scale');bcscale($oldScale);
$decimalTier=$bwSchema;$bwIndex=array_search(278145,array_column($decimalTier['config_groups'][0]['options'],'id'),true);
$decimalTier['config_groups'][0]['options'][$bwIndex]['sub'][1]['pricings'][0]['monthly']='0.29';
$q=$bwDefaults;$q[278145]='1000';
expectPrice($decimalTier,$q,'monthly','574.50','574.50','fractional tier unit price multiplied across950 units');
$bwFees=$bwSchema;
$bwFees['config_groups'][0]['options'][$bwIndex]['sub'][0]['pricings'][0]['msetupfee']='11.00';
$bwFees['config_groups'][0]['options'][$bwIndex]['sub'][1]['pricings'][0]['msetupfee']='3.00';
$q=$bwDefaults;$q[278145]='60';
expectPrice($bwFees,$q,'monthly','402.00','399.00','type18 setup uses current tier only');

// Entire combination falls back on malformed/unsupported rules, including unused matrix entries.
$mutations=[
    'missing flag'=>static function(&$s){unset($s['flag']);},
    'account discount'=>static function(&$s){$s['flag']=['type'=>1,'bates'=>90];},
    'unknown policy'=>static function(&$s){$s['products']['upstream_price_type']='fixed';},
    'resource price policy'=>static function(&$s){$s['products']['api_type']='resource';},
    'tax'=>static function(&$s){$s['products']['tax']=1;},
    'proration'=>static function(&$s){$s['products']['prorata_billing']=1;},
    'missing policy'=>static function(&$s){unset($s['products']['prorata_charge_next_month']);},
    'postpaid'=>static function(&$s){$s['products']['pay_method']='postpaid';},
    'free product'=>static function(&$s){$s['products']['pay_type']='{"pay_type":"free"}';},
    'damaged policy JSON'=>static function(&$s){$s['products']['pay_type']='{';},
    'precision beyond supported matrix'=>static function(&$s){$s['product_pricings'][0]['monthly']='1.001';},
    'float price'=>static function(&$s){$s['product_pricings'][0]['monthly']=1.01;},
    'disabled cycle'=>static function(&$s){$s['product_pricings'][0]['monthly']='-1.00';},
    'currency mismatch'=>static function(&$s){$s['product_pricings'][0]['code']='USD';},
    'currency ID mismatch'=>static function(&$s){$s['product_pricings'][0]['currency']=2;},
    'damaged currency ID'=>static function(&$s){$s['product_pricings'][0]['currency']=[];},
    'wrong price owner'=>static function(&$s){$s['product_pricings'][0]['relid']=1;},
    'duplicate currency row'=>static function(&$s){$s['product_pricings'][]=$s['product_pricings'][0];},
    'missing base matrix'=>static function(&$s){unset($s['product_pricings']);},
    'unused option missing price'=>static function(&$s){unset($s['config_groups'][0]['options'][1]['sub'][1]['pricings']);},
    'unused option missing setup'=>static function(&$s){unset($s['config_groups'][0]['options'][1]['sub'][1]['pricings'][0]['msetupfee']);},
    'negative option price'=>static function(&$s){$s['config_groups'][0]['options'][1]['sub'][1]['pricings'][0]['monthly']='-1.00';},
    'missing groups'=>static function(&$s){unset($s['config_groups']);},
    'missing linked group'=>static function(&$s){$s['config_groups']=[];},
    'missing group links'=>static function(&$s){unset($s['config_links']);},
    'duplicate linked group'=>static function(&$s){$s['config_links'][]=$s['config_links'][0];},
    'broken group'=>static function(&$s){$s['config_groups'][0]=null;},
    'broken options'=>static function(&$s){$s['config_groups'][0]['options']='damaged';},
    'broken sub'=>static function(&$s){$s['config_groups'][0]['options'][0]['sub']=[null];},
    'unsupported custom input'=>static function(&$s){$s['customfields']=[['id'=>1]];},
    'unsupported option type'=>static function(&$s){$s['config_groups'][0]['options'][0]['option_type']=20;},
];
foreach($mutations as $label=>$mutate){
    $bad=$base;$mutate($bad);$calls=0;
    $r=$comparison->compare(envelope($bad),'monthly',$defaults,$now,static function()use(&$calls,$defaults){$calls++;return new PreflightResult('257.00',$defaults,recurringAmount:'257.00');});
    check($calls===1 && $r['status']==='online_only' && !$r['local']['supported'] && !isset($r['local']['first']) && $r['upstream']['first']==='257.00','whole-combination fallback: '.$label);
}
foreach([null,[],['schema'=>'broken','fetched_at'=>$now],envelope($base)+['extra'=>'ignored']] as $i=>$snapshot){
    if($i===3){$snapshot['fetched_at']=$now-901;}
    $calls=0;$r=$comparison->compare($snapshot,'monthly',$defaults,$now,static function()use(&$calls,$defaults){$calls++;return new PreflightResult('257.00',$defaults,recurringAmount:'257.00');});
    check($calls===1 && $r['status']==='online_only' && !$r['local']['supported'] && !isset($r['local']['first']), 'missing/damaged/expired snapshot '.$i.' uses online without local amount');
}
foreach([-1,901] as $age){$s=envelope($base);$s['fetched_at']=$now-$age;check(!$calculator->evaluate($s,'monthly',$defaults,$now)['supported'],'future or stale snapshot refuses '.$age);}
$s=envelope($base);$s['fetched_at']=$now-900;check($calculator->evaluate($s,'monthly',$defaults,$now)['supported'],'inclusive maximum snapshot age');
foreach(['quarterly','onetime'] as $cycle){check(!$calculator->evaluate(envelope($base),$cycle,$defaults,$now)['supported'],'unsupported cycle '.$cycle);}
foreach(['0','6','1.5','1174910'] as $n){$bad=$defaults;$bad[277296]=$n;check(!$calculator->evaluate(envelope($base),'monthly',$bad,$now)['supported'],'invalid integer combination '.$n);}
$bad=$bwDefaults;$bad[278145]='51';check(!$calculator->evaluate(envelope($bwSchema),'monthly',$bad,$now)['supported'],'tier boundary51 is invalid by step, not guessed');
$illegal=options($bwSchema);$illegal[278141]='1178976';check(!$calculator->evaluate(envelope($bwSchema),'monthly',$illegal,$now)['supported'],'enum dependency rejected before local pricing');
$bad=$base;$bad['config_groups'][0]['options'][$ipIndex]['sub'][1]['qty_minimum']=3;
check(!$calculator->evaluate(envelope($bad),'monthly',$defaults,$now)['supported'],'tier gap rejected even below affected tier');

$local=$calculator->evaluate(envelope($base),'monthly',$defaults,$now);
$changed=$base;$changed['products']['qty']=0;$changed['products']['stock_control']=1;$s=envelope($changed);$s['fetched_at']=$now-1;
check($calculator->evaluate($s,'monthly',$defaults,$now)['pricing_hash']===$local['pricing_hash'],'stock and freshness excluded from diagnostic content identity');
$changed=$base;$changed['products']['upstream_price_value']='100.01';
check($calculator->evaluate(envelope($changed),'monthly',$defaults,$now)['pricing_hash']!==$local['pricing_hash'],'price policy affects diagnostic identity');
$changed=$base;$changed['product_pricings'][0]['monthly']='1.01';
check($calculator->evaluate(envelope($changed),'monthly',$defaults,$now)['pricing_hash']!==$local['pricing_hash'],'matrix price change affects identity');
$changed=$base;$changed['config_groups'][0]['options'][$ipIndex]['qty_stage']=2;
check($calculator->evaluate(envelope($changed),'monthly',$defaults,$now)['pricing_hash']!==$local['pricing_hash'],'quantity step change affects identity even with same default price');
$r=$comparison->compare(envelope($base),'monthly',$defaults,$now,fn()=>new PreflightResult('258.00',$defaults,context:['schema_hash'=>$local['schema_hash']],recurringAmount:'257.00'));
check($r['status']==='mismatch' && $r['upstream']['first']==='258.00','cent/amount mismatch never promoted to local success');
$r=$comparison->compare(envelope($base),'monthly',$defaults,$now,fn()=>new PreflightResult('257.00',$defaults,context:['schema_hash'=>'different'],recurringAmount:'257.00'));
check($r['status']==='rules_changed','equal amounts do not hide form rule drift');
foreach([fn()=>throw new RuntimeException('timeout'),fn()=>new PreflightResult('257.00',[],recurringAmount:'257.00'),fn()=>new PreflightResult('257.00',$defaults,'USD',recurringAmount:'257.00'),fn()=>new PreflightResult('0.00',$defaults,recurringAmount:'257.00')] as $online){
    try{$comparison->compare(envelope($base),'monthly',$defaults,$now,$online);throw new LogicException('Should fail');}
    catch(RuntimeException){check(true,'upstream failure or inconsistent response never serves local price');}
}
// Saved CLI roundtrip, mismatch status, invalid data and output secret hygiene.
function cli(array $input): array {
    $pipes=[];$process=proc_open([PHP_BINARY,dirname(__DIR__).'/plugins/providers/zjmf/compare.php','--saved'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($input,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);return [proc_close($process),$out,$err];
}
$input=['snapshot'=>envelope($base),'observed_at'=>$now,'billing_cycle'=>'monthly','config_options'=>$defaults,'upstream_config_options'=>$defaults,
    'upstream_schema_hash'=>$local['schema_hash'],'upstream_quote'=>['status'=>200,'currency'=>['code'=>'CNY'],'products'=>['total'=>'257.00','signal_price'=>'257.00','billingcycle'=>'monthly','type'=>false]],'password'=>'SENTINEL_PRIVATE_KEY'];
[$exit,$out,$err]=cli($input);check($exit===0 && json_decode($out,true)['status']==='matched' && $err==='' && !str_contains($out,'SENTINEL_PRIVATE_KEY'),'CLI saved replay matches without echoing credentials or raw snapshot');
$input['upstream_quote']['products']['signal_price']='258.00';[$exit,$out]=cli($input);check($exit===2 && json_decode($out,true)['status']==='mismatch','CLI exits nonzero on recurring-only mismatch');
$input['upstream_quote']['products']['type']=['type'=>1];[$exit,$out,$err]=cli($input);check($exit===1 && $out==='' && !str_contains($err,'SENTINEL_PRIVATE_KEY'),'CLI rejects unsupported saved policy with sanitized error');
restore_error_handler();
echo "Local pricing checks: $checks\n";
