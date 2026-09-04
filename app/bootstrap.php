<?php
/**
 * PMD 引导文件
 */
declare(strict_types=1);

define('PMD_ROOT', dirname(__DIR__));
define('PMD_APP', PMD_ROOT . '/app');
define('PMD_PUBLIC', PMD_ROOT . '/public');
define('PMD_STORAGE', PMD_APP . '/storage');
define('PMD_LANG', PMD_APP . '/lang');
define('PMD_VERSION', '3.0.1');

// 目录检查（安装时创建）
foreach ([PMD_STORAGE . '/tmp', PMD_STORAGE . '/logs', PMD_PUBLIC . '/uploads/logo'] as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@ini_set('error_log', PMD_STORAGE . '/logs/php-error.log');

// 时区
date_default_timezone_set('Asia/Shanghai');

require_once PMD_APP . '/lib/functions.php';

// 是否已安装（存在 config.php）
define('PMD_INSTALLED', file_exists(PMD_APP . '/config.php'));

if (PMD_INSTALLED) {
    $pmd_config = require PMD_APP . '/config.php';
    $GLOBALS['pmd_config'] = $pmd_config;

    // 会话
    if (session_status() === PHP_SESSION_NONE) {
        $sname = $pmd_config['session']['name'] ?? 'PMDSESSID';
        $slife = (int)($pmd_config['session']['lifetime'] ?? 28800);
        session_name($sname);
        ini_set('session.gc_maxlifetime', (string)$slife);
        session_set_cookie_params(['lifetime' => $slife, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

// 自动加载 app/lib 下的类
spl_autoload_register(function (string $class): void {
    $file = PMD_APP . '/lib/' . strtolower($class) . '.php';
    if (is_file($file)) {
        require_once $file;
        return;
    }
    // 多类共存的特殊文件
    $multi = ['xlsxwriter' => 'xlsx', 'xlsxreader' => 'xlsx'];
    $key = strtolower($class);
    if (isset($multi[$key])) {
        $f = PMD_APP . '/lib/' . $multi[$key] . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
});

// 初始化 i18n
I18n::init();
