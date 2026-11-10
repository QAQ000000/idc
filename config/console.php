<?php

return [
    // Exact cloud console host:port, approved by the operator and distinct from the financial API domain.
    'targets' => array_values(array_filter(explode(',', env('CONSOLE_TARGETS', '')))),
    'test_loopback' => env('APP_ENV') === 'test' && env('CONSOLE_TEST_LOOPBACK') === '1',
];
