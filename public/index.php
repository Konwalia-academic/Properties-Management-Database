<?php
/**
 * PMD 前端控制器
 * - /api/* → API 路由
 * - 其余 → 单页应用外壳
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// PHP 内置服务器路由器模式：已存在的文件（静态资源 / api.php / install.php）直接放行
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

if (preg_match('#^/api(/|$)#', $path)) {
    require_once __DIR__ . '/../app/api.php';
    exit;
}

// 安装向导（兜底：某些路由模式下需要显式放行）
if ($path === '/install.php') {
    defined('PMD_ROOT') || define('PMD_ROOT', dirname(__DIR__));
    defined('PMD_APP') || define('PMD_APP', PMD_ROOT . '/app');
    require __DIR__ . '/install.php';
    exit;
}

if (!PMD_INSTALLED) {
    header('Location: /install.php');
    exit;
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

$title = '个人物品管理数据库';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='3' y='5' width='26' height='22' rx='3' fill='%232563eb'/%3E%3Ctext x='16' y='23' font-size='13' font-family='sans-serif' font-weight='bold' fill='white' text-anchor='middle'%3EPMD%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<div id="app"></div>
<script src="/assets/js/app.js"></script>
</body>
</html>
