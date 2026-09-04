<?php
/**
 * PMD 数据库初始化脚本（PHP/PDO 版，字符集安全）
 *
 * 用途：
 *   - 本地部署（tools/local_deploy.sh）与服务器初始化时执行 sql/init.sql，
 *     全程使用 PDO + charset=utf8mb4，彻底避免 mysql CLI 字符集导致的
 *     中文乱码（UTF-8 二次编码）问题；
 *   - 初始化后自动做编码自检，发现已有乱码数据时调用 Categories::repairCharset() 修复。
 *
 * 用法：
 *   php tools/seed.php [db_host] [db_port] [db_name] [db_user] [db_pass]
 *   不带参数时读取 app/config.php 中的数据库配置。
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PMD_ROOT', dirname(__DIR__));
define('PMD_APP', PMD_ROOT . '/app');

function seed_usage(): void
{
    fwrite(STDERR, "用法: php tools/seed.php [db_host] [db_port] [db_name] [db_user] [db_pass]\n");
    exit(1);
}

$cfgFile = PMD_APP . '/config.php';
if ($argc >= 6) {
    $cfg = ['host' => $argv[1], 'port' => (int)$argv[2], 'name' => $argv[3], 'user' => $argv[4], 'pass' => $argv[5], 'charset' => 'utf8mb4'];
} elseif (is_file($cfgFile)) {
    $c = require $cfgFile;
    $cfg = $c['db'] ?? null;
    if ($cfg === null) {
        fwrite(STDERR, "config.php 缺少 db 配置\n");
        exit(1);
    }
} else {
    seed_usage();
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $cfg['host'], (int)$cfg['port']),
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int)$cfg['port'], $cfg['name']),
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

// 执行 init.sql（去注释 + 按分号拆分，跳过建库/USE 语句）
$sql = (string)file_get_contents(PMD_ROOT . '/sql/init.sql');
$sql = preg_replace('/--[^\n]*/m', '', $sql);
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
$stmts = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');
$n = 0;
foreach ($stmts as $stmt) {
    if (preg_match('/^(CREATE\s+DATABASE|USE\s+)/i', $stmt)) {
        continue;
    }
    $pdo->exec($stmt);
    $n++;
}
echo "init.sql 执行完成：{$n} 条语句\n";

// 编码自检：R-SN 子类别名称应为「收纳用品」UTF-8（E694B6E7BAB3E794A8E59381）
$st = $pdo->query("SELECT HEX(sub_name) FROM categories WHERE main_code='R' AND sub_code='SN'");
$hex = strtoupper((string)$st->fetchColumn());
if ($hex === 'E694B6E7BAB3E794A8E59381') {
    echo "编码检查：类别名称 UTF-8 正确 ✔\n";
} else {
    echo "编码检查：检测到异常（HEX=" . ($hex === '' ? '(空)' : $hex) . "），尝试修复乱码…\n";
    $GLOBALS['pmd_config'] = ['db' => $cfg];
    require_once PMD_APP . '/lib/db.php';
    require_once PMD_APP . '/lib/categories.php';
    $r = Categories::repairCharset();
    echo "修复完成：扫描 {$r['scanned']} 行，修复 {$r['fixed']} 处\n";
    foreach ($r['tables'] as $t => $c) {
        echo "  - {$t}: {$c} 处\n";
    }
    $st = DB::val("SELECT HEX(sub_name) FROM categories WHERE main_code='R' AND sub_code='SN'");
    echo '复查 HEX=' . (string)$st . (strtoupper((string)$st) === 'E694B6E7BAB3E794A8E59381' ? " ✔ 已恢复正常\n" : " ✘ 仍需人工处理\n");
}
echo "初始化完成。\n";
