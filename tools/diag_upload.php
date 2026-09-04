<?php
/**
 * PMD 上传故障诊断脚本（针对"文件保存失败"）
 *
 * 用法（服务器上）：
 *   1. 把本文件放到 PMD 项目根目录（与 app/ 同级），如 /var/www/PMD/tools/diag_upload.php
 *   2. 命令行执行：  php tools/diag_upload.php
 *      若服务器不支持命令行 PHP，可临时复制到 public/ 下用浏览器访问（诊断完删除）。
 *
 * 只做读取 + 在 storage/tmp 写一个测试文件并立即删除，不会改动业务数据。
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$storage = $root . '/app/storage';
$tmp = $storage . '/tmp';
$logs = $storage . '/logs';
$logo = $root . '/public/uploads/logo';

function who(int $uid): string
{
    if (function_exists('posix_getpwuid')) {
        $u = posix_getpwuid($uid);
        if ($u) {
            return $u['name'] . "(uid $uid)";
        }
    }
    return (string)$uid;
}

function pdir(string $label, string $dir): void
{
    $exists = is_dir($dir);
    $writable = $exists && is_writable($dir);
    $perm = $exists ? substr(sprintf('%o', fileperms($dir)), -4) : '-';
    $owner = $exists ? who(fileowner($dir)) : '-';
    printf("%-24s %s | 可写:%-3s | 权限:%s | 属主:%s\n", $label, $exists ? '存在' : '缺失', $writable ? '是' : '否', $perm, $owner);
}

echo "========== PHP 环境 ==========\n";
echo 'PHP 版本       : ', PHP_VERSION, "\n";
echo '当前运行用户   : ', function_exists('posix_geteuid') ? who(posix_geteuid()) : get_current_user(), "\n";
echo 'upload_max_filesize: ', ini_get('upload_max_filesize'), "\n";
echo 'post_max_size  : ', ini_get('post_max_size'), "\n";
echo 'file_uploads   : ', ini_get('file_uploads'), "\n";
echo 'upload_tmp_dir : ', ini_get('upload_tmp_dir') ?: ('(系统默认 ' . sys_get_temp_dir() . ')'), "\n";
echo 'open_basedir   : ', ini_get('open_basedir') ?: '(未限制)', "\n";
echo 'display_errors : ', ini_get('display_errors'), "\n";

echo "\n========== 目录状态 ==========\n";
pdir('app/storage', $storage);
pdir('app/storage/tmp', $tmp);
pdir('app/storage/logs', $logs);
pdir('public/uploads/logo', $logo);

echo "\n========== 模拟 Upload::save 写入测试 ==========\n";
if (!is_dir($tmp)) {
    @mkdir($tmp, 0775, true);
    echo "tmp 目录原本不存在，已尝试创建 -> ", is_dir($tmp) ? "成功" : "失败", "\n";
}
$test = $tmp . '/diag_test_' . bin2hex(random_bytes(4)) . '.tmp';
$n = @file_put_contents($test, 'pmd-diag');
if ($n !== false) {
    @unlink($test);
    echo "✅ 写入 " . basename($tmp) . " 成功。PHP 进程对 tmp 目录有写权限。\n";
    echo "   （若权限正常但仍报“文件保存失败”，重点查：open_basedir / SELinux / 磁盘满）\n";
} else {
    echo "❌ 写入 " . basename($tmp) . " 失败 —— 与“文件保存失败”同因：PHP 进程对 tmp 目录无写权限。\n";
    echo "   修复：sudo chown -R www-data:www-data $root/app/storage\n";
    echo "         sudo chmod -R 775 $root/app/storage\n";
    echo "   （www-data 换成实际运行用户，见上方“当前运行用户/属主”对照）\n";
}

echo "\n========== 磁盘空间 ==========\n";
$free = @disk_free_space($root);
echo '项目所在盘剩余: ', $free === false ? '无法获取' : round($free / 1048576, 1) . ' MB', "\n";

echo "\n========== 近期 PHP 错误日志 ==========\n";
$logFile = $logs . '/php-error.log';
if (is_file($logFile)) {
    $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -15);
    echo implode("\n", $lines ?: ['(日志为空)']), "\n";
} else {
    echo "(无 php-error.log，可检查: journalctl -u php-fpm / tail /var/log/php*fpm.log / nginx error.log)\n";
}

echo "\n========== SELinux 提示 ==========\n";
if (is_file('/sys/fs/selinux/enforce')) {
    $enforce = (int)trim((string)file_get_contents('/sys/fs/selinux/enforce'));
    echo 'SELinux 状态: ', $enforce ? "强制执行中\n" : "关闭\n";
    if ($enforce) {
        echo "若目录权限正常但仍失败，多半是 SELinux 拦截：\n";
        echo "  sudo chcon -R -t httpd_sys_rw_content_t $root/app/storage $root/public/uploads\n";
    }
} else {
    echo "SELinux 未启用（或无法读取）。\n";
}
