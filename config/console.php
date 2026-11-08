<?php
return [
    'pid_file' => env('CONSOLE_PID_FILE', storage_path('framework/console-relay.pid')),
    'listen' => env('CONSOLE_LISTEN', '127.0.0.1:18213'),
    'origins' => array_values(array_filter(explode(',', env('CONSOLE_ORIGINS', '')))),
    // Exact host:port supplied by the operator; never learned from an untrusted response.
    'targets' => array_values(array_filter(explode(',', env('CONSOLE_TARGETS', '')))),
    'test_loopback' => env('APP_ENV') === 'test' && env('CONSOLE_TEST_LOOPBACK') === '1',
];
