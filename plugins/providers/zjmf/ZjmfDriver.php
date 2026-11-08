<?php
declare(strict_types=1);
namespace Plugins\providers\zjmf;
use App\Core\Money;
use App\Plugins\Providers\{UpstreamDriverInterface, RenewalDriverInterface, DriverExecutionResult as R, PreflightResult, ConfigurableDriverInterface, ConfigOptions, PasswordPolicy, PowerDriverInterface, PowerState, ConsoleDriverInterface, ConsoleTarget};

/** Magic Finance agent API. Stateless cart_data avoids clearing or sharing the upstream account's cart. */
final class ZjmfDriver implements UpstreamDriverInterface, RenewalDriverInterface, ConfigurableDriverInterface, PowerDriverInterface, ConsoleDriverInterface
{
    public function getDriverId(): string { return 'zjmf'; }
    public function getQueryAuthority(): string { return 'EVENTUAL_CONSISTENCY'; }
    private function request(array $c, string $path, array $params = [], string $method = 'GET', ?string $token = null): array
    {
        $url = rtrim($c['endpoint'], '/') . $path;
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['https','http'], true)
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)) { throw new \RuntimeException('Invalid provider endpoint'); }
        if ($method === 'GET' && $params) { $url .= '?' . http_build_query($params); }
        $curl = curl_init($url);
        $headers = ['Accept: application/json']; if ($token !== null) { $headers[] = 'Authorization: Bearer ' . $token; }
        $body = '';
        curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_NOPROXY => '*',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int { if (strlen($body) + strlen($chunk) > 2097152) { return 0; } $body .= $chunk; return strlen($chunk); }]);
        if ($method === 'POST') { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params)); }
        $ok = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($ok === false || $status !== 200) { throw new \RuntimeException('Provider transport failed'); }
        $r = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($r)) { throw new \RuntimeException('Invalid provider response'); }
        return $r;
    }
    private function token(array $c): string
    {
        $r = $this->request($c, '/zjmf_api_login', ['username' => $c['username'], 'password' => $c['password']], 'POST');
        if ((int)($r['status'] ?? 0) !== 200 || !is_string($r['jwt'] ?? null)) { throw new \RuntimeException('Provider authentication failed'); }
        return $r['jwt'];
    }
    public function getFormSchema(int $remotePid, array $credentials): array
    {
        $r = $this->request($credentials, '/cart/get_product_config', ['pid' => $remotePid], token: $this->token($credentials));
        if ((int)($r['status'] ?? 0) !== 200 || !is_array($r['data'] ?? null)) { throw new \RuntimeException('Product schema unavailable'); }
        return $r['data'];
    }
    public function describeForm(array $snapshot): array
    {
        if (!empty($snapshot['customfields']) || !empty($snapshot['advanced'])) { throw new \RuntimeException('Custom fields and advanced linkage are not supported'); }
        $form = [];
        $label = static fn(string $name): string => str_replace('^', ' / ', explode('|', $name, 2)[1] ?? $name);
        foreach ($snapshot['config_groups'] ?? [] as $group) {
            foreach ($group['options'] ?? [] as $option) {
                if (!empty($option['hidden']) || !in_array((int)($option['option_type'] ?? 0), [1,2,5,6,8,10,12,13], true)
                    || !empty($option['linkage_pid']) || !empty($option['linkage_top_pid'])) { throw new \RuntimeException('Unsupported option or linkage'); }
                $id = (string)($option['id'] ?? '');
                if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id) || isset($form[$id])) { throw new \RuntimeException('Invalid option identity'); }
                $choices = [];
                foreach ($option['sub'] ?? [] as $sub) {
                    if (!empty($sub['hidden'])) { continue; }
                    $value = (string)($sub['id'] ?? '');
                    if (!preg_match('/^[1-9][0-9]{0,9}$/D', $value) || isset($choices[$value])) { throw new \RuntimeException('Invalid option value'); }
                    $choices[$value] = ['value'=>$value, 'label'=>$label((string)$sub['option_name'])];
                }
                if (!$choices || count($choices)>500) { throw new \RuntimeException('Invalid option choices'); }
                $form[$id] = ['id'=>$id, 'type'=>'select', 'label'=>$label((string)$option['option_name']), 'choices'=>array_values($choices), 'default'=>array_values($choices)[0]['value']];
            }
        }
        if (count($form)>100) { throw new \RuntimeException('Too many configuration options'); }
        return array_values($form);
    }
    /** Product JSON uses generation flags, not merely optional password complexity hints. */
    private function inputContext(array $schema): array
    {
        $context = ['hostname_rule'=>'alnum8', 'password_policy'=>['min_length'=>12,'max_length'=>20]];
        $product = $schema['products'] ?? [];
        $decode = static function (mixed $value): array {
            $rule = is_string($value) ? json_decode($value,true,32,JSON_THROW_ON_ERROR) : $value;
            if (!is_array($rule) || !is_array($rule['rule']??null)) { throw new \RuntimeException('Invalid upstream generation rules'); }
            return $rule;
        };
        if (isset($product['password'])) {
            $settings = $decode($product['password']); $rule = $settings['rule']; $sets = [];
            foreach (['upper'=>'upper','lower'=>'lower','num'=>'digit','special'=>'special'] as $key=>$name) {
                $flag = $rule[$key]??($key==='special'?0:1);
                if (!in_array($flag,[0,1,'0','1'],true)) { throw new \RuntimeException('Invalid password generation flag'); }
                if ((int)$flag===1 && !($key==='special' && ($product['type']??'')==='dcimcloud')) { $sets[]=$name; }
            }
            $length = $rule['len_num']??12;
            if (!is_scalar($length) || !preg_match('/^[0-9]{1,3}$/D',(string)$length)) { throw new \RuntimeException('Invalid password generation length'); }
            $context['password_policy'] += ['character_sets'=>$sets,'generation_length'=>max(12,min(20,(int)$length))];
            PasswordPolicy::bounds($context);
        }
        if (isset($product['host'])) {
            $settings = $decode($product['host']); $rule = $settings['rule']; $sets=[];
            foreach (['upper','lower','num'] as $key) {
                $flag=$rule[$key]??0;
                if (!in_array($flag,[0,1,'0','1'],true)) { throw new \RuntimeException('Invalid hostname generation flag'); }
                if ((int)$flag===1) { $sets[]=$key==='num'?'digit':$key; }
            }
            if (!$sets) { $sets=['lower','upper','digit']; }
            $prefix = (int)($settings['show']??0)===0 ? 'ser' : ($settings['prefix']??'cloud');
            $length = $rule['len_num']??12;
            if (!is_string($prefix) || !preg_match('/^[a-zA-Z0-9_-]{0,10}$/D',$prefix)
                || !is_scalar($length) || !preg_match('/^[0-9]{1,3}$/D',(string)$length)) { throw new \RuntimeException('Invalid hostname generation rule'); }
            $length=(int)$length; $length=$length<4?12:min(20,$length);
            $context['hostname_rule']='schema_v1';
            $context['hostname_policy']=['prefix'=>$prefix,'length'=>$length,'character_sets'=>$sets];
        }
        return $context;
    }
    public function preflightPrice(int $remotePid, string $billingCycle, array $selectedConfig, array $credentials): PreflightResult
    {
        if (!in_array($billingCycle, ['monthly','annually'], true)) { throw new \RuntimeException('Unsupported billing cycle'); }
        $token = $this->token($credentials);
        $schema = $this->request($credentials, '/cart/get_product_config', ['pid' => $remotePid], token: $token);
        if ((int)($schema['status'] ?? 0) !== 200) { throw new \RuntimeException('Product schema unavailable'); }
        $form = $this->describeForm($schema['data'] ?? []);
        $config = ConfigOptions::validate($form, $selectedConfig);
        // get_total uses singular configoption; settle uses plural configoptions.
        $r = $this->request($credentials, '/cart/get_total', ['pid' => $remotePid, 'billingcycle' => $billingCycle, 'qty' => 1, 'configoption'=>$config], 'POST', $token);
        $p = $r['products'] ?? [];
        if ((int)($r['status'] ?? 0) !== 200 || ($r['currency']['code'] ?? '') !== 'CNY'
            || ($p['billingcycle'] ?? '') !== $billingCycle || ((int)($p['stock_control'] ?? 0) === 1 && (int)($p['qty'] ?? 0) < 1)
            || !empty($p['type'])) { throw new \RuntimeException('Provider quote unavailable or promotional pricing unsupported'); }
        $amount = Money::positive((string)($p['total'] ?? ''));
        // Legacy fixed snapshots retain their historical equal first/renewal price fallback.
        if ($form && !isset($p['signal_price'])) { throw new \RuntimeException('Recurring price missing'); }
        $recurring = Money::positive((string)($p['signal_price'] ?? $amount));
        // Magic/DCIM provisioning accepts at most 20 bytes, even when settle accepts more.
        // Keep the local 12-byte minimum. This is not the product's generation length.
        return new PreflightResult($amount, $config, context: $this->inputContext($schema['data']??[]) + ['schema_hash'=>ConfigOptions::hash($form)], recurringAmount: $recurring);
    }
    private function hostname(array $o): string
    {
        $hash = hash('sha256', $o['order_namespace'] . ':' . $o['idempotency_key'], true);
        // Preserve the original identity for already-submitted orders during the rule change.
        if (($o['driver_context']['hostname_rule'] ?? null) === null) { return 'jy-' . substr(bin2hex($hash), 0, 36); }
        if ($o['driver_context']['hostname_rule']==='schema_v1') {
            $p=$o['driver_context']['hostname_policy']; $characters='';
            foreach ($p['character_sets'] as $set) { $characters.=PasswordPolicy::CHARACTER_SETS[$set]; }
            $name=$p['prefix'];
            for ($i=0;$i<$p['length'];$i++) { $name.=$characters[ord($hash[$i])%strlen($characters)]; }
            return $name;
        }
        if ($o['driver_context']['hostname_rule'] !== 'alnum8') { throw new \RuntimeException('Unknown frozen hostname rule'); }
        $alphabet = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $name = '0123456789'[ord($hash[0]) % 10] . 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[ord($hash[1]) % 26] . 'abcdefghijklmnopqrstuvwxyz'[ord($hash[2]) % 26];
        for ($i = 3; $i < 8; $i++) { $name .= $alphabet[ord($hash[$i]) % 62]; }
        return $name;
    }
    private function matches(array $host, array $o, ?string $hostId): bool
    {
        // Account-scoped IDs returned by settlement/payment are stable; names can be edited upstream.
        // Without a known ID, only the frozen order name can identify a candidate.
        return ($hostId === null ? ($host['domain'] ?? '') === $this->hostname($o) : (string)($host['id'] ?? '') === $hostId)
            && (int)($host['productid'] ?? $host['pid'] ?? 0) === (int)$o['remote_pid']
            && ($host['billingcycle'] ?? '') === $o['billing_cycle']
            && bccomp((string)($host['firstpaymentamount'] ?? '-1'), $o['upstream_total'], 2) === 0;
    }
    private function inspect(array $o, array $c, string $token, ?string $hostId, ?string $operation): R
    {
        if ($hostId !== null) {
            $r = $this->request($c, '/cart/hostinfo', ['hostid' => [$hostId]], token: $token);
            $hosts = $r['data']['hosts'] ?? [];
        } else {
            $r = $this->request($c, '/host/list', ['search' => $this->hostname($o), 'limit' => 50, 'page' => 1], token: $token);
            if ((int)($r['data']['sum'] ?? 0) > 50) { return new R('unknown', operationId: $operation, code: 'AMBIGUOUS_REMOTE_MATCH'); }
            $hosts = $r['data']['list'] ?? [];
        }
        if ((int)($r['status'] ?? 0) !== 200) { return new R('unknown', operationId: $operation, code: 'QUERY_UNAVAILABLE'); }
        $matches = array_values(array_filter($hosts, fn($h) => $this->matches($h, $o, $hostId)));
        if (count($matches) !== 1) { return new R('unknown', operationId: $operation, code: count($matches) > 1 ? 'AMBIGUOUS_REMOTE_MATCH' : 'NOT_YET_VISIBLE'); }
        $h = $matches[0]; $id = (string)$h['id'];
        if ($hostId === null) { return $this->inspect($o, $c, $token, $id, 'host:' . $id); }
        if (($h['domainstatus'] ?? '') !== 'Active') { return new R('pending', operationId: 'host:' . $id, code: 'AWAITING_REMOTE_ACTIVATION'); }
        $ip = filter_var($h['dedicatedip'] ?? '', FILTER_VALIDATE_IP) ? $h['dedicatedip'] : null;
        return new R('succeeded', 'host:' . $id, $ip, 'host:' . $id, 'DELIVERED', $h['username'] ?? null, $h['password'] ?? null);
    }
    public function create(array $orderData, array $credentials): R
    {
        $op = null; $submitted = false;
        try {
            $quote = $this->preflightPrice((int)$orderData['remote_pid'], $orderData['billing_cycle'], $orderData['config_options'], $credentials);
            try { PasswordPolicy::validate($orderData['password'], $quote->context); }
            catch (\App\Core\ApiException) { return new R('rejected', code: 'INVALID_INITIAL_PASSWORD'); }
            // New orders freeze the upstream generation contract; never silently substitute an identity.
            if (($orderData['driver_context']['hostname_rule']??null)==='schema_v1'
                && (($quote->context['hostname_policy']??null)!=($orderData['driver_context']['hostname_policy']??null)
                    || ($quote->context['password_policy']??null)!=($orderData['driver_context']['password_policy']??null))) {
                return new R('rejected',code:'INPUT_RULES_CHANGED_BEFORE_EXECUTION');
            }
            if (bccomp($quote->amount, $orderData['upstream_total'], 2) !== 0
                || (isset($orderData['driver_context']['schema_hash']) && $quote->context['schema_hash'] !== $orderData['driver_context']['schema_hash'])
                || (isset($orderData['upstream_recurring']) && bccomp($quote->recurringAmount, $orderData['upstream_recurring'], 2) !== 0)) { return new R('rejected', code: 'PRICE_CHANGED_BEFORE_EXECUTION'); }
            $token = $this->token($credentials);
            $data = ['pid' => $orderData['remote_pid'], 'billingcycle' => $orderData['billing_cycle'], 'host' => $this->hostname($orderData),
                'password' => $orderData['password'], 'qty' => 1, 'configoptions' => $orderData['config_options']];
            // No automatic transport retry. Unknown settle/apply_credit results enter read-only investigation.
            $submitted = true;
            $r = $this->request($credentials, '/cart/settle', ['cart_data' => $data], 'POST', $token);
            if ((int)($r['status'] ?? 0) === 1001) {
                $ids = $r['data']['hostid'] ?? []; $hostId = count($ids) === 1 ? (string)$ids[0] : null;
                return $this->inspect($orderData, $credentials, $token, $hostId, $hostId ? 'host:' . $hostId : null);
            }
            $invoiceId = (int)($r['data']['invoiceid'] ?? 0);
            if ((int)($r['status'] ?? 0) !== 200 || $invoiceId < 1) { return new R('unknown', code: 'UPSTREAM_SETTLEMENT_UNCONFIRMED'); }
            $op = 'invoice:' . $invoiceId;
            $invoice = $this->request($credentials, '/invoice_page', ['invoiceid' => $invoiceId], 'POST', $token);
            if ((int)($invoice['status'] ?? 0) !== 200 || bccomp((string)($invoice['data']['invoice_total'] ?? '-1'), $orderData['upstream_total'], 2) !== 0
                || bccomp((string)($invoice['data']['invoice_subtotal'] ?? '-1'), $orderData['upstream_total'], 2) !== 0) {
                return new R('unknown', operationId: $op, code: 'REMOTE_INVOICE_AMOUNT_REQUIRES_REVIEW');
            }
            $paid = $this->request($credentials, '/apply_credit', ['invoiceid' => $invoiceId, 'use_credit' => 1, 'enough' => 1], 'POST', $token);
            if ((int)($paid['status'] ?? 0) !== 1001) { return new R('unknown', operationId: $op, code: 'UPSTREAM_PAYMENT_UNCONFIRMED'); }
            $ids = $paid['data']['hostid'] ?? []; $hostId = count($ids) === 1 ? (string)$ids[0] : null;
            return $this->inspect($orderData, $credentials, $token, $hostId, $hostId ? 'host:' . $hostId : $op);
        } catch (\Throwable) { return new R($submitted ? 'unknown' : 'not_executed', operationId: $op, code: $submitted ? 'UPSTREAM_RESULT_UNKNOWN' : 'PREFLIGHT_NO_REQUEST_SENT'); }
    }
    public function queryOrder(array $orderData, array $credentials): R
    {
        $op = $orderData['operation_id'] ?? null;
        try {
            $hostId = is_string($op) && preg_match('/^host:([0-9]+)$/D', $op, $m) ? $m[1] : null;
            return $this->inspect($orderData, $credentials, $this->token($credentials), $hostId, $op);
        } catch (\Throwable) { return new R('unknown', operationId: $op, code: 'QUERY_UNAVAILABLE'); }
    }

    private function renewalHost(array $o, array $c, string $token): array
    {
        if (!preg_match('/^host:([0-9]+)$/D',$o['remote_instance_id']??'', $m)) { throw new \RuntimeException('Invalid renewal resource'); }
        $r=$this->request($c,'/cart/hostinfo',['hostid'=>[$m[1]]],token:$token);
        $hosts=$r['data']['hosts']??[];
        if (($r['status']??0)!==200 || count($hosts)!==1 || (string)($hosts[0]['id']??'')!==$m[1]
            || (int)($hosts[0]['productid']??0)!==(int)$o['remote_pid'] || ($hosts[0]['billingcycle']??'')!==$o['billing_cycle']
            || !is_numeric($hosts[0]['nextduedate']??null) || (int)$hosts[0]['nextduedate']<1) {
            throw new \RuntimeException('Renewal resource ownership or billing identity mismatch');
        }
        return $hosts[0];
    }

    public function preflightRenewal(array $instance, array $credentials): PreflightResult
    {
        $token=$this->token($credentials);$host=$this->renewalHost($instance,$credentials,$token);
        if (!in_array($host['domainstatus']??'', ['Active','Suspended'],true)) { throw new \RuntimeException('Resource cannot renew'); }
        if ((int)($host['initiative_renew']??0)!==0) { throw new \RuntimeException('Upstream automatic renewal must be disabled before local orchestration'); }
        $r=$this->request($credentials,'/host/renewpage',['hostid'=>$host['id'],'billingcycles'=>$instance['billing_cycle']],token:$token);
        $data=$r['data']??[];$h=$data['host']??[];
        $cycles=array_values(array_filter($data['cycle']??[],fn($cycle)=>($cycle['billingcycle']??'')===$instance['billing_cycle']));
        if (($r['status']??0)!==200 || ($data['currency']['code']??'')!=='CNY' || count($cycles)!==1
            || (int)($h['productid']??0)!==(int)$instance['remote_pid'] || ($h['billingcycle']??'')!==$instance['billing_cycle']
            || (int)($h['nextduedate']??0)!==(int)$host['nextduedate'] || (int)($cycles[0]['duration']??0)<=0
            || bccomp(Money::positive((string)($h['amount']??'')),Money::positive((string)$host['amount']),2)!==0) {
            throw new \RuntimeException('Renewal quote unavailable');
        }
        return new PreflightResult(Money::positive((string)$h['amount']),[],context:[
            'remote_base_due'=>(int)$host['nextduedate'],'remote_target_due'=>(int)$host['nextduedate']+(int)$cycles[0]['duration'],
        ]);
    }

    private function inspectRenewal(array $o,array $c,string $token,?string $operation): R
    {
        $h=$this->renewalHost($o,$c,$token);$context=$o['renewal_context']??[];
        if ((int)$h['nextduedate']!==($context['remote_target_due']??null)) {
            return new R('unknown',operationId:$operation,code:'RENEWAL_DATE_UNCONFIRMED');
        }
        // Epay/credit settlement on Magic Finance may also schedule unsuspension. We only observe it.
        if (($h['domainstatus']??'')!=='Active') { return new R('pending',operationId:$operation,code:'AWAITING_REMOTE_RESTORE'); }
        return new R('succeeded',$o['remote_instance_id'],operationId:$operation,code:'RENEWED');
    }

    public function renew(array $order,array $credentials): R
    {
        $submitted=false;$operation=null;
        try {
            $token=$this->token($credentials);$h=$this->renewalHost($order,$credentials,$token);$context=$order['renewal_context']??[];
            if ((int)$h['nextduedate']===($context['remote_target_due']??null)) { return $this->inspectRenewal($order,$credentials,$token,null); }
            $quote=$this->preflightRenewal($order,$credentials);
            if ($quote->context!==$context || bccomp($quote->amount,$order['upstream_total'],2)!==0) { return new R('rejected',code:'RENEWAL_QUOTE_CHANGED'); }
            // No second submission/payment after timeout, including a lost invoice creation response.
            $submitted=true;
            $r=$this->request($credentials,'/host/renew',['hostid'=>$h['id'],'billingcycles'=>$order['billing_cycle']],'POST',$token);
            $invoiceId=(int)($r['data']['invoiceid']??0);
            if (($r['status']??0)!==200 || $invoiceId<1) { return new R('unknown',code:'RENEWAL_SUBMISSION_UNCONFIRMED'); }
            $operation='invoice:'.$invoiceId;
            $invoice=$this->request($credentials,'/invoice_page',['invoiceid'=>$invoiceId],'POST',$token);
            if (($invoice['status']??0)!==200 || bccomp((string)($invoice['data']['invoice_total']??'-1'),$order['upstream_total'],2)!==0
                || bccomp((string)($invoice['data']['invoice_subtotal']??'-1'),$order['upstream_total'],2)!==0) {
                return new R('unknown',operationId:$operation,code:'REMOTE_RENEWAL_AMOUNT_REQUIRES_REVIEW');
            }
            $r=$this->request($credentials,'/apply_credit',['invoiceid'=>$invoiceId,'use_credit'=>1,'enough'=>1],'POST',$token);
            if (($r['status']??0)!==1001) { return new R('unknown',operationId:$operation,code:'RENEWAL_PAYMENT_UNCONFIRMED'); }
            return $this->inspectRenewal($order,$credentials,$token,$operation);
        } catch(\Throwable) { return new R($submitted?'unknown':'not_executed',operationId:$operation,code:$submitted?'RENEWAL_RESULT_UNKNOWN':'RENEWAL_PREFLIGHT_NO_REQUEST_SENT'); }
    }

    public function queryRenewal(array $order,array $credentials): R
    {
        $operation=$order['operation_id']??null;
        try{return $this->inspectRenewal($order,$credentials,$this->token($credentials),$operation);}
        catch(\Throwable){return new R('unknown',operationId:$operation,code:'RENEWAL_QUERY_UNAVAILABLE');}
    }
    private function powerHost(array $o, array $c, string $token): array
    {
        // Use the frozen instance binding, never a customer-supplied upstream id or today's catalog.
        $host = $this->renewalHost($o, $c, $token);
        if (($host['domainstatus'] ?? '') !== 'Active') { throw new \RuntimeException('Remote resource is not active'); }
        return $host;
    }

    public function openConsole(array $instance, array $credentials): ConsoleTarget
    {
        $token=$this->token($credentials);$host=$this->powerHost($instance,$credentials,$token);
        $header=$this->request($credentials,'/host/header',['host_id'=>$host['id']],token:$token);
        $data=$header['data']??[];
        if (($header['status']??0)!==200 || (string)($data['host_data']['id']??'')!==(string)$host['id']
            || (int)($data['host_data']['productid']??0)!==(int)$instance['remote_pid']) { throw new \RuntimeException('Console binding mismatch'); }
        $supported=false;
        foreach($data['module_button']['console']??[] as $button){
            if(($button['type']??'')==='default' && ($button['func']??'')==='vnc'){$supported=true;}
        }
        if(!$supported || (isset($data['dcim']['auth']['vnc']) && $data['dcim']['auth']['vnc']!=='on')){throw new \RuntimeException('Console unsupported');}
        $r=$this->request($credentials,'/provision/default',['id'=>$host['id'],'func'=>'vnc','is_api'=>1],'POST',$token);
        if(($r['status']??0)!==200 || !empty($r['data']['zjmfcloud_out_vnc'])){throw new \RuntimeException('Console mode unsupported');}
        $page=$r['data']['url']??'';$p=parse_url($page);$endpoint=parse_url($credentials['endpoint']);
        if(!$p || ($p['scheme']??'')!==($endpoint['scheme']??'') || ($p['host']??'')!==($endpoint['host']??'')
            || ($p['port']??null)!==($endpoint['port']??null) || ($p['path']??'')!=='/dcim/novnc' || isset($p['user']) || isset($p['fragment'])){throw new \RuntimeException('Console page unsupported');}
        parse_str($p['query']??'',$q);
        $url=is_string($q['url']??null)?base64_decode($q['url'],true):false;
        $password=$q['password']??$r['data']['pass']??null;
        if(!$url || !is_string($password) || strlen($password)>128){throw new \RuntimeException('Console response invalid');}
        return new ConsoleTarget($url,$password);
    }

    public function inspectPower(array $instance, array $credentials): PowerState
    {
        $token = $this->token($credentials);
        $host = $this->powerHost($instance, $credentials, $token);
        $r = $this->request($credentials, '/host/header', ['host_id'=>$host['id']], token:$token);
        $data = $r['data'] ?? [];
        if (($r['status'] ?? 0) !== 200 || (string)($data['host_data']['id'] ?? '') !== (string)$host['id']
            || (int)($data['host_data']['productid'] ?? 0) !== (int)$instance['remote_pid']) {
            throw new \RuntimeException('Power capability resource mismatch');
        }
        if (($data['module_power_status'] ?? false) !== true && ($data['module_power_status'] ?? 0) !== 1) {
            return new PowerState('unsupported');
        }
        $actions = [];
        foreach ($data['module_button']['control'] ?? [] as $button) {
            if (($button['type'] ?? '') !== 'default') { continue; }
            $action = ['on'=>'start','off'=>'stop','reboot'=>'reboot'][$button['func'] ?? ''] ?? null;
            // DCIM can additionally deny individual controls. Never replace off with hard_off.
            $auth = $data['dcim']['auth'] ?? [];
            if ($action !== null && (!isset($auth[$button['func']]) || $auth[$button['func']] === 'on')) { $actions[] = $action; }
        }
        $r = $this->request($credentials, '/provision/default', ['id'=>$host['id'],'func'=>'status','is_api'=>1], 'POST', $token);
        if (($r['status'] ?? 0) !== 200) { throw new \RuntimeException('Power status unavailable'); }
        $state = match ($r['data']['status'] ?? '') {
            'on'=>'on', 'off'=>'off', 'waiting','process','cold_migrate','hot_migrate','paused'=>'transitioning',
            'not_support'=>'unsupported', default=>'unknown',
        };
        return new PowerState($state, array_values(array_unique($actions)));
    }

    public function executePower(array $operation, array $credentials): R
    {
        $action = $operation['power_action'] ?? '';
        $func = ['start'=>'on','stop'=>'off','reboot'=>'reboot'][$action] ?? null;
        if ($func === null) { return new R('rejected', code:'POWER_UNSUPPORTED'); }
        $state = $this->inspectPower($operation, $credentials);
        if (!in_array($action, $state->actions, true)
            || ($action === 'start' ? $state->state !== 'off' : $state->state !== 'on')) {
            // No mutating request was sent; the original operation is never retried automatically.
            return new R('rejected', code:'POWER_PRECONDITION_CHANGED');
        }
        preg_match('/^host:([0-9]+)$/D', $operation['remote_instance_id'], $m);
        $r = $this->request($credentials, '/provision/default', ['id'=>$m[1],'func'=>$func,'is_api'=>1], 'POST', $this->token($credentials));
        // Non-200 business responses can hide a module timeout: absence of acknowledgement is UNKNOWN.
        return ($r['status'] ?? 0) === 200
            ? new R('succeeded', $operation['remote_instance_id'], code:'POWER_ACCEPTED')
            : new R('unknown', code:'POWER_RESULT_UNCONFIRMED');
    }

    public function queryPower(array $operation, array $credentials): R
    {
        $this->inspectPower($operation, $credentials);
        // This API exposes no request-specific command receipt; even on->on does not prove a reboot.
        return new R('unknown', code:'NON_QUERYABLE');
    }

}
