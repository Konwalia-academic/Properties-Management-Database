<?php
/**
 * PMD 模板生成脚本（命令行）
 * 用法：php tools/generate_templates.php
 * 生成：templates/ 下的导入模板与作业单模板（.xlsx/.csv/.sql）
 *
 * 说明：项目已附带生成好的模板文件，无需重复执行；
 * 若你修改了 app/lib/templates.php 中的列定义，可重新运行本脚本刷新模板。
 */
declare(strict_types=1);

define('PMD_ROOT', dirname(__DIR__));
define('PMD_APP', PMD_ROOT . '/app');

require_once PMD_APP . '/lib/functions.php';
require_once PMD_APP . '/lib/xlsx.php';
require_once PMD_APP . '/lib/csvio.php';
require_once PMD_APP . '/lib/templates.php';

$outDir = PMD_ROOT . '/templates';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

function write_xlsx(string $file, array $rows, string $sheetName = 'Sheet1'): void
{
    $w = new XlsxWriter();
    $w->addSheet($sheetName, $rows);
    $w->save($file);
    echo "  ✔ " . basename($file) . "\n";
}

echo "生成 PMD 模板文件…\n";

// 1. 物品导入模板（xlsx + csv）
write_xlsx($outDir . '/物品导入模板_items_import_template.xlsx', [
    Templates::itemsHeaderRow(),
    ['HBG001', 'A4复印纸（示例）', '得力', 'HOME', '', '', 25, 10, 5, '包', 80, '示例行，导入前请删除', 'H', 'BG', '6901234567890', 'A'],
], '物品导入');
CsvIO::write($outDir . '/物品导入模板_items_import_template.csv', [
    Templates::itemsHeaderRow(),
    ['HBG001', 'A4复印纸（示例）', '得力', 'HOME', '', '', 25, 10, 5, '包', 80, '示例行，导入前请删除', 'H', 'BG', '6901234567890', 'A'],
]);
echo "  ✔ 物品导入模板_items_import_template.csv\n";

// 2. 物资交换作业单模板
write_xlsx($outDir . '/物资交换作业单模板_exchange_worksheet_template.xlsx', [
    Templates::exchangeHeaderRow(),
    ['NDZ001', '蓝牙键盘（示例）', 'HOME', 'OFFC', '示例行，请替换或删除'],
], '物资交换作业单');

// 3. 物资采购欲购清单模板
write_xlsx($outDir . '/物资采购欲购清单模板_purchase_list_template.xlsx', [
    Templates::purchaseHeaderRow(),
    ['NDZ001', '蓝牙键盘（示例）', '罗技', 'N', 'DZ', 'HOME', '个', 1, 1, 199, '示例行，请替换或删除', 'A'],
], '物资采购欲购清单');

echo "完成。模板目录：" . $outDir . "\n";
