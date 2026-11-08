<?php
declare(strict_types=1);

use Laravel\Boost\Mcp\Tools\{BrowserLogs, DatabaseConnections, DatabaseQuery, DatabaseSchema, LastError, ReadLogEntries, RecordRule, Tinker};

// Only the isolated CLI launcher opts in; installing dev dependencies must not add HTTP routes.
return [
    'enabled' => PHP_SAPI === 'cli' && env('JIANYUN_BOOST_ENABLED', false),
    'browser_logs_watcher' => false,
    'rules' => ['enabled' => false],
    'executable_paths' => ['php' => PHP_BINARY],
    'mcp' => [
        'tool_timeout' => 30,
        'tools' => [
            'exclude' => array_merge(
                [BrowserLogs::class, LastError::class, ReadLogEntries::class, RecordRule::class, Tinker::class],
                env('JIANYUN_BOOST_DATABASE', false) ? [] : [DatabaseConnections::class, DatabaseQuery::class, DatabaseSchema::class],
            ),
        ],
    ],
];
