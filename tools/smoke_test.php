<?php
/**
 * PMD 冒烟测试（无需数据库）
 * 用法：php tools/smoke_test.php
 * 覆盖：XLSX 写入/读取往返、CSV 读写、SQL 解析、表头映射、序列号格式校验
 */
declare(strict_types=1);

define('PMD_ROOT', dirname(__DIR__));
define('PMD_APP', PMD_ROOT . '/app');

require_once PMD_APP . '/lib/xlsx.php';
require_once PMD_APP . '/lib/csvio.php';
require_once PMD_APP . '/lib/templates.php';

// 语言桩（不依赖数据库；不加载 functions.php 以免重复定义 t/tf）
class I18n
{
    public static function t(string $k): string { return $k; }
}
function t(string $k): string { return $k; }
function tf(string $k, array $p = []): string { return $k; }

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ✔ $name\n"; }
    else { $fail++; echo "  ✘ $name" . ($detail ? " — $detail" : '') . "\n"; }
}

echo "== 1. XLSX 写入/读取往返 ==\n";
$tmp = tempnam(sys_get_temp_dir(), 'pmd_test_');
$w = new XlsxWriter();
$w->addSheet('测试表', [
    ['序列号', '物品名称', '余量', '折旧'],
    ['NDZ121', '蓝牙键盘', 2, 85],
    ['HBG001', 'A4纸, "特价"', 10, 40],
    ['', '', 0, 100],
]);
$w->save($tmp);
check('文件生成', filesize($tmp) > 1000, 'size=' . filesize($tmp));

$rows = XlsxReader::read($tmp);
check('读取行数=4（含数值0的行保留）', count($rows) === 4, 'got ' . count($rows));
check('表头正确', ($rows[0][0] ?? '') === '序列号' && ($rows[0][3] ?? '') === '折旧');
check('数字单元格', (string)($rows[1][2] ?? '') === '2');
check('特殊字符保留', ($rows[2][1] ?? '') === 'A4纸, "特价"');
check('空行跳过', ($rows[2][0] ?? '') === 'HBG001' && count($rows[2]) === 4);

echo "== 2. CSV 读写 ==\n";
$csvTmp = tempnam(sys_get_temp_dir(), 'pmd_csv_');
CsvIO::write($csvTmp, [
    ['序列号', '名称'],
    ['NDZ001', '含,逗号与"引号"'],
]);
$csvRows = CsvIO::read($csvTmp);
check('CSV 行数=2', count($csvRows) === 2);
check('CSV 引号转义', ($csvRows[1][1] ?? '') === '含,逗号与"引号"');

echo "== 3. SQL 解析（INSERT INTO items）==\n";
require_once PMD_APP . '/lib/importer.php';
$sqlTmp = tempnam(sys_get_temp_dir(), 'pmd_sql_');
file_put_contents($sqlTmp, "-- 注释\nINSERT INTO items (serial_no, name, main_category, sub_category, quantity) VALUES ('HBG001','A4纸','H','BG',10), ('HBG002','A5纸','H','BG',5);\nDELETE FROM items; -- 应被忽略\nINSERT INTO other (a) VALUES (1);\n");
$parsed = Importer::parseSql($sqlTmp);
check('解析出 2 条 INSERT 元组', count($parsed['stmts']) === 2, 'got ' . count($parsed['stmts']));
check('忽略 2 条非目标语句', $parsed['ignored'] === 2, 'got ' . $parsed['ignored']);
check('字段映射正确', ($parsed['stmts'][0]['cols'][0] ?? '') === 'serial_no');
check('字符串值解析', ($parsed['stmts'][0]['values'][1] ?? '') === 'A4纸');
$rows = Importer::sqlToRows($parsed['stmts']);
check('sqlToRows 行数=2', count($rows) === 2);
check('sqlToRows 值对齐', ($rows[1]['serial_no'] ?? '') === 'HBG002' && ($rows[1]['quantity'] ?? '') === '5');

echo "== 4. 表头映射 ==\n";
$mapped = Templates::mapRows([
    ['序列号', '物品名称', '余量', '备注'],
    ['NDZ001', '键盘', '3', '新'],
], Templates::ITEMS_HEADERS);
check('中文表头映射', ($mapped[0]['serial_no'] ?? '') === 'NDZ001' && ($mapped[0]['quantity'] ?? '') === '3' && ($mapped[0]['notes'] ?? '') === '新');

$mapped2 = Templates::mapRows([
    ['serial_no', 'name', 'quantity'],
    ['NDZ002', '鼠标', '1'],
], Templates::ITEMS_HEADERS);
check('英文表头别名', ($mapped2[0]['serial_no'] ?? '') === 'NDZ002' && ($mapped2[0]['name'] ?? '') === '鼠标');

echo "\n结果：$pass 通过 / $fail 失败\n";
@unlink($tmp);
@unlink($csvTmp);
@unlink($sqlTmp);
exit($fail > 0 ? 1 : 0);
