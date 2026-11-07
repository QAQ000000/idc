<?php
// MVP 不启用框架默认的签名文件上传/下载路由；下载领域另按归属契约实现。
return [
    'default' => 'local',
    'disks' => ['local' => [
        'driver' => 'local', 'root' => storage_path('app/private'), 'serve' => false, 'throw' => true,
    ]],
];
