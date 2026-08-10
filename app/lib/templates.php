<?php
/**
 * PMD 模板定义与行列转换
 *
 * 三类模板：
 *   items     物品导入模板（新增/合并）
 *   exchange  物资交换作业单
 *   purchase  物资采购欲购清单
 */
declare(strict_types=1);

class Templates
{
    // ---------------- 列定义（表头 → 字段） ----------------

    public const ITEMS_HEADERS = [
        '序列号' => 'serial_no',
        '物品名称' => 'name',
        '品牌' => 'brand',
        '目前所在位置代码' => 'location_code',
        '新所在位置代码' => 'new_location_code',
        '所在容器序列号' => 'container_serial',
        '购入价格' => 'purchase_price',
        '余量' => 'quantity',
        '季度消耗量' => 'quarterly_consumption',
        '单位' => 'unit',
        '仓储/折旧情况(%)' => 'depreciation',
        '备注' => 'notes',
        '物品母类别' => 'main_category',
        '物品子类别' => 'sub_category',
        '商品条形码' => 'barcode',
    ];

    public const EXCHANGE_HEADERS = [
        '序列号' => 'serial_no',
        '物品名称' => 'name',
        '目前所在位置代码' => 'location_code',
        '新所在位置代码' => 'new_location_code',
        '备注' => 'notes',
    ];

    public const PURCHASE_HEADERS = [
        '序列号' => 'serial_no',
        '物品名称' => 'name',
        '品牌' => 'brand',
        '物品母类别' => 'main_category',
        '物品子类别' => 'sub_category',
        '目前所在位置代码' => 'location_code',
        '单位' => 'unit',
        '当前余量' => 'quantity',
        '采购数量' => 'purchase_qty',
        '购入价格' => 'purchase_price',
        '备注' => 'notes',
    ];

    // 表头别名（兼容手工修改/英文表头）
    private const ALIASES = [
        'serial_no' => ['序列号', 'serial_no', 'serial', '编号'],
        'name' => ['物品名称', 'name', '名称'],
        'brand' => ['品牌', 'brand'],
        'location_code' => ['目前所在位置代码', 'location_code', '目前所在位置', '位置代码'],
        'new_location_code' => ['新所在位置代码', 'new_location_code', '新所在位置', '新位置代码'],
        'container_serial' => ['所在容器序列号', 'container_serial', '容器序列号', '所在容器'],
        'purchase_price' => ['购入价格', 'purchase_price', '价格', '单价'],
        'quantity' => ['余量', 'quantity', '当前余量', '库存'],
        'purchase_qty' => ['采购数量', 'purchase_qty', '欲购数量', '购买数量'],
        'quarterly_consumption' => ['季度消耗量', 'quarterly_consumption'],
        'unit' => ['单位', 'unit'],
        'depreciation' => ['仓储/折旧情况(%)', 'depreciation', '仓储/折旧情况', '折旧'],
        'notes' => ['备注', 'notes', 'note'],
        'main_category' => ['物品母类别', 'main_category', '母类别'],
        'sub_category' => ['物品子类别', 'sub_category', '子类别'],
        'barcode' => ['商品条形码', 'barcode', '条形码'],
    ];

    /**
     * 解析二维表 → 按表头映射的关联数组行
     * @throws RuntimeException 无法识别表头
     */
    public static function mapRows(array $rows, array $expectedHeaders): array
    {
        if (!$rows) {
            throw new RuntimeException(t('val.unknownHeader'));
        }
        $header = $rows[0];
        $colMap = []; // colIdx => field
        foreach ($header as $idx => $h) {
            $h = trim((string)$h);
            if ($h === '') {
                continue;
            }
            foreach (self::ALIASES as $field => $aliases) {
                if (in_array($h, $aliases, true)) {
                    $colMap[$idx] = $field;
                    break;
                }
            }
        }
        if (!$colMap) {
            throw new RuntimeException(t('val.unknownHeader'));
        }
        $out = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $item = [];
            foreach ($colMap as $idx => $field) {
                $item[$field] = $row[$idx] ?? '';
            }
            $out[] = $item;
        }
        return $out;
    }

    /** 物品记录 → 导入模板行 */
    public static function itemToRow(array $item): array
    {
        $row = [];
        foreach (self::ITEMS_HEADERS as $header => $field) {
            $v = $item[$field] ?? '';
            if ($field === 'purchase_price' && $v !== '') {
                $v = rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
            }
            $row[] = $v;
        }
        return $row;
    }

    public static function itemsHeaderRow(): array
    {
        return array_keys(self::ITEMS_HEADERS);
    }

    public static function exchangeHeaderRow(): array
    {
        return array_keys(self::EXCHANGE_HEADERS);
    }

    public static function purchaseHeaderRow(): array
    {
        return array_keys(self::PURCHASE_HEADERS);
    }
}
