<?php
/**
 * PMD 配置文件（示例）
 * 由网页安装向导自动生成 app/config.php；
 * 如需手动配置，请复制本文件为 config.php 并填写。
 */
return [
    // 数据库连接
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'pmd',
        'user'    => 'pmd',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    // 会话
    'session' => [
        'name'   => 'PMDSESSID',
        'lifetime' => 28800, // 秒，8小时
    ],
    // 上传限制（字节）
    'upload_max_bytes' => 10 * 1024 * 1024, // 10MB
];
