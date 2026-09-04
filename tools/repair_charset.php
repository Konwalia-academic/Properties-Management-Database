<?php
/**
 * PMD 中文乱码修复工具
 *
 * 适用场景：旧版本部署时因 mysql CLI 字符集设置不当，导致类别/位置/设置/物品/借还记录
 * 中的中文被「UTF-8 二次编码」（如「收纳用品」变成「æ”¶çº³ç”¨å“」）。
 * 本工具自动扫描并修复全库文本字段，不影响正常数据。
 *
 * 用法：php tools/repair_charset.php
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PMD_ROOT', dirname(__DIR__));
define('PMD_APP', PMD_ROOT . '/app');

$cfgFile = PMD_APP . '/config.php';
if (!is_file($cfgFile)) {
    fwrite(STDERR, "未找到 app/config.php，请先完成安装部署。\n");
    exit(1);
}
$cfg = require $cfgFile;
$GLOBALS['pmd_config'] = $cfg;

require_once PMD_APP . '/lib/db.php';
require_once PMD_APP . '/lib/categories.php';

$r = Categories::repairCharset();
echo "扫描 {$r['scanned']} 行，修复 {$r['fixed']} 处乱码\n";
foreach ($r['tables'] as $t => $c) {
    echo "  - {$t}: {$c} 处\n";
}
echo $r['fixed'] > 0 ? "修复完成，请刷新页面查看。\n" : "未发现乱码。\n";
