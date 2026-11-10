<?php

// Standalone upstream compatibility test. Never boots ThinkPHP or reads its configuration.
// Usage: php test_structured_task_type.php /path/to/patched/source-copy

namespace think {
    class Db
    {
        public static function name($name)
        {
            return new self();
        }

        public function __call($name, $arguments)
        {
            if ($name === 'find') {
                return ['serverid' => 1, 'dcimid' => 37, 'uid' => 9];
            }

            return $this;
        }
    }
}

namespace {
    $root = $argv[1] ?? '';
    if (! is_file($root.'/app/common/logic/DcimCloud.php') || ! is_file($root.'/app/common/logic/Host.php')) {
        fwrite(STDERR, "Provide the patched upstream source copy\n");
        exit(1);
    }
    define('CMF_ROOT', $root.'/');
    class TaskTypeClientActivityLog
    {
        public static function isSupplierApiType($type)
        {
            return in_array($type, ['zjmf_api', 'resource', 'manual', 'whmcs'], true);
        }
    }
    class_alias('TaskTypeClientActivityLog', 'app\\common\\logic\\ClientActivityLog');
    require $root.'/app/common/logic/DcimCloud.php';
    require $root.'/app/common/logic/Host.php';
    class TaskTypeCloud extends \app\common\logic\DcimCloud
    {
        public $response;

        public $calls = [];

        public function setUrl($id)
        {
            $this->error = false;
        }

        public function curl($action = '', $data = [], $timeout = 30, $request = 'POST', $relogin = true)
        {
            $this->calls[] = [$action, $data, $timeout, $request];

            return $this->response;
        }
    }
    $checks = 0;
    function check($ok, $message)
    {
        global $checks;
        if (! $ok) {
            fwrite(STDERR, "FAIL: $message\n");
            exit(1);
        }
        $checks++;
    }
    $cloud = new TaskTypeCloud();
    $host = new \app\common\logic\Host();
    $project = new \ReflectionMethod($host, 'statusDataForClient');
    $project->setAccessible(true);
    $base = ['status' => 'task', 'task' => 'reinstall', 'task_name' => 'INTERNAL-SUPPLIER',
        'password' => 'SECRET', 'operation_id' => 'PRIVATE-ID', 'endpoint' => 'https://private.invalid'];
    $cloud->response = ['status' => 'success', 'data' => $base];
    $mapped = $cloud->getCloudStatus(123);
    check($cloud->calls === [['/clouds/37/status', [], 20, 'GET']], 'read-only original cloud status route');
    check($mapped['data']['task_type'] === 'reinstall', 'project machine task type');
    foreach (['zjmf_api', 'resource', 'manual', 'whmcs'] as $type) {
        check($project->invoke($host, $mapped, $type) === ['status' => 'process', 'des' => '任务处理中', 'task_type' => 'reinstall'],
            'proxy chain preserves only known type, status and sanitized description: '.$type);
    }
    foreach (['snapshot_create', 'reboot', 'REINSTALL', ' reinstall ', null, 3, true, ['reinstall']] as $value) {
        $cloud->response = ['status' => 'success', 'data' => array_replace($base, ['task' => $value])];
        $result = $cloud->getCloudStatus(123);
        check(! isset($result['data']['task_type']), 'unknown or malformed raw cloud task suppressed');
        check($project->invoke($host, $result, 'zjmf_api') === ['status' => 'process', 'des' => '任务处理中'], 'untyped process stays untyped');
    }
    foreach (['snapshot_create', 3, ['reinstall'], null] as $value) {
        $result = ['data' => ['status' => 'process', 'des' => 'PRIVATE', 'task_type' => $value, 'password' => 'SECRET']];
        check($project->invoke($host, $result, 'zjmf_api') === ['status' => 'process', 'des' => '任务处理中'], 'proxy rejects invalid projected type');
    }
    foreach (['on', 'off', 'suspend', 'wait_reboot', 'paused', 'cold_migrate', 'hot_migrate', 'other'] as $status) {
        $cloud->response = ['status' => 'success', 'data' => array_replace($base, ['status' => $status])];
        check(! isset($cloud->getCloudStatus(123)['data']['task_type']), 'idle or non-task cannot carry execution type');
    }
    check($project->invoke($host, ['data' => ['status' => 'on', 'task_type' => 'reinstall']], 'zjmf_api') === ['status' => 'on', 'des' => '开机'], 'stale typed idle is stripped');
    $diagnostic = ['status' => 'process', 'des' => 'PRIVATE', 'task_type' => 'reinstall', 'diagnostic' => 'INTERNAL'];
    $host->is_admin = true;
    check($project->invoke($host, ['data' => $diagnostic], 'zjmf_api') === $diagnostic, 'admin diagnostics unchanged');
    $host->is_admin = false;
    check($project->invoke($host, ['data' => $diagnostic], '') === $diagnostic, 'direct module behavior unchanged');
    echo "$checks upstream structured task checks passed.\n";
}
