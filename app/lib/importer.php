<?php
/**
 * PMD 导入引擎：物品（xlsx/csv/sql）、交换作业单、采购清单
 */
declare(strict_types=1);

class Importer
{
    // ============================================================
    // 物品导入
    // ============================================================

    /**
     * 预览物品导入行
     * @return array{rows:array, inserted:int, duplicates:int, invalid:int}
     */
    public static function previewItems(array $rows): array
    {
        $out = [];
        $inserted = $duplicates = $invalid = 0;
        $autoCats = [];
        foreach ($rows as $i => $r) {
            $rowNo = $i + 2; // 表头占第 1 行
            $serial = strtoupper(clean_str($r['serial_no'] ?? ''));
            $name = clean_str($r['name'] ?? '');
            $errors = [];
            $autoCat = false;

            if ($serial !== '' && preg_match('/^[A-Z]{3}[0-9]{3}$/', $serial) !== 1) {
                $errors[] = t('val.serialFormat');
            }
            if ($name === '') {
                $errors[] = t('val.nameRequired');
            }
            if ($serial === '') {
                $main = strtoupper(clean_str($r['main_category'] ?? ''));
                $sub = strtoupper(clean_str($r['sub_category'] ?? ''));
                if ($main === '' || $sub === '') {
                    $errors[] = t('val.categoryNeeded');
                } elseif (!isset(Serial::MAIN[$main])) {
                    $errors[] = t('val.mainInvalid');
                } elseif (preg_match('/^[A-Z]{2}$/', $sub) !== 1) {
                    $errors[] = t('val.subInvalid');
                } elseif (!Serial::subValid($main, $sub)) {
                    // 组合不存在：自动创建，不视为错误
                    Categories::ensure($main, $sub);
                    $autoCat = true;
                }
            } else {
                $main = $serial[0];
                $sub = substr($serial, 1, 2);
                if (!Serial::subValid($main, $sub)) {
                    Categories::ensure($main, $sub);
                    $autoCat = true;
                }
            }

            // 数值校验（轻量）
            $qty = clean_str($r['quantity'] ?? '');
            if ($qty !== '' && !is_non_negative_num($qty)) {
                $errors[] = t('items.quantityWarn');
            }
            $dep = clean_str($r['depreciation'] ?? '');
            if ($dep !== '' && (!is_natural($dep) || (int)$dep > 100)) {
                $errors[] = t('items.depWarn');
            }
            $price = clean_str($r['purchase_price'] ?? '');
            if ($price !== '' && !is_numeric($price)) {
                $errors[] = '购入价格必须为数字';
            }
            $loc = strtoupper(clean_str($r['location_code'] ?? ''));
            if ($loc !== '' && preg_match(Items::LOCATION_RE, $loc) !== 1) {
                $errors[] = t('val.locationInvalid');
            }
            $nloc = strtoupper(clean_str($r['new_location_code'] ?? ''));
            if ($nloc !== '' && preg_match(Items::LOCATION_RE, $nloc) !== 1) {
                $errors[] = t('val.locationInvalid');
            }
            $cs = strtoupper(clean_str($r['container_serial'] ?? ''));
            if ($cs !== '' && preg_match('/^[A-Z]{3}[0-9]{3}$/', $cs) !== 1) {
                $errors[] = t('val.containerInvalid');
            }

            $isDup = false;
            if ($serial !== '' && !$errors) {
                $isDup = DB::one('SELECT serial_no FROM items WHERE serial_no = ?', [$serial]) !== null;
            }

            if ($errors) {
                $invalid++;
            } elseif ($isDup) {
                $duplicates++;
            } else {
                $inserted++;
            }

            if ($autoCat && !$errors) {
                $autoCats[$main . '-' . $sub] = [
                    'main_code' => $main,
                    'sub_code' => $sub,
                    'main_name' => Serial::MAIN[$main],
                    'sub_name' => Categories::SUB_DEFAULT[$sub] ?? $sub,
                ];
            }

            $out[] = [
                'row_no' => $rowNo,
                'serial_no' => $serial,
                'name' => $name,
                'is_dup' => $isDup,
                'errors' => $errors,
                'data' => $r,
            ];
        }
        return ['rows' => $out, 'inserted' => $inserted, 'duplicates' => $duplicates, 'invalid' => $invalid, 'auto_categories' => array_values($autoCats)];
    }

    /**
     * 应用物品导入
     * @param array $previewRows previewItems 返回的 rows
     * @param string $dupMode 'update' | 'skip'
     */
    public static function applyItems(array $previewRows, string $dupMode): array
    {
        $inserted = $updated = $skipped = 0;
        $errors = [];
        foreach ($previewRows as $p) {
            if ($p['errors']) {
                $errors[] = '第 ' . $p['row_no'] . ' 行：' . implode('；', $p['errors']);
                continue;
            }
            $d = $p['data'];
            try {
                if ($p['is_dup']) {
                    if ($dupMode === 'update') {
                        $item = Items::find($p['serial_no']);
                        // 非空单元格覆盖已有值
                        $merge = [];
                        foreach (Items::FIELDS as $f) {
                            $v = clean_str($d[$f] ?? '');
                            if ($f === 'purchase_price' && $v === '') continue;
                            if ($f === 'quantity' && $v === '') continue;
                            if ($f === 'quarterly_consumption' && $v === '') continue;
                            if ($f === 'depreciation' && $v === '') continue;
                            if ($v !== '' && $item !== null && $f !== 'serial_no') {
                                $merge[$f] = $v;
                            }
                        }
                        if ($merge) {
                            Items::update($p['serial_no'], $merge);
                        } else {
                            Items::touch($p['serial_no']);
                        }
                        $updated++;
                    } else {
                        $skipped++;
                    }
                } else {
                    Items::create($d);
                    $inserted++;
                }
            } catch (RuntimeException $e) {
                $errors[] = '第 ' . $p['row_no'] . ' 行：' . $e->getMessage();
            }
        }
        return ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ============================================================
    // SQL 导入
    // ============================================================

    /**
     * 解析 SQL 文件：仅提取 INSERT INTO items 语句
     * @return array{stmts:array{cols:array,tuples:array[]}, ignored:int}
     * @throws RuntimeException
     */
    public static function parseSql(string $path): array
    {
        $sql = (string)file_get_contents($path);
        $sql = preg_replace('/--[^\n]*/m', '', $sql); // 行注释
        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql); // 块注释
        $stmts = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');

        $out = ['stmts' => [], 'ignored' => 0];
        foreach ($stmts as $stmt) {
            if (preg_match('/^INSERT\s+INTO\s+`?items`?\s*(\(([^)]*)\))?\s*VALUES\s*(.*)$/is', $stmt, $m) !== 1) {
                $out['ignored']++;
                continue;
            }
            $cols = [];
            if (!empty($m[2])) {
                $cols = array_map(fn($c) => strtolower(trim(str_replace('`', '', $c))), explode(',', $m[2]));
            }
            $tuples = self::extractTuples($m[3]);
            if (!$cols) {
                $out['ignored']++;
                continue;
            }
            foreach ($tuples as $t) {
                $out['stmts'][] = ['cols' => $cols, 'values' => $t];
            }
        }
        return $out;
    }

    /** 提取 VALUES 后的 (v1, v2, ...) 元组（支持单引号字符串与 '' 转义） */
    private static function extractTuples(string $s): array
    {
        $tuples = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '(') {
                $depth = 0;
                $buf = '';
                $inStr = false;
                for (; $i < $len; $i++) {
                    $ch = $s[$i];
                    if ($inStr) {
                        $buf .= $ch;
                        if ($ch === "'") {
                            if (($s[$i + 1] ?? '') === "'") {
                                $buf .= "'";
                                $i++;
                            } else {
                                $inStr = false;
                            }
                        }
                        continue;
                    }
                    if ($ch === "'") {
                        $inStr = true;
                        $buf .= $ch;
                    } elseif ($ch === '(') {
                        $depth++;
                        $buf .= $ch;
                    } elseif ($ch === ')') {
                        $depth--;
                        $buf .= $ch;
                        if ($depth === 0) {
                            $tuples[] = self::splitValues($buf);
                            $i++;
                            break;
                        }
                    } else {
                        $buf .= $ch;
                    }
                }
            } else {
                $i++;
            }
        }
        return $tuples;
    }

    /** 拆分元组内的值（去掉首尾括号，尊重引号） */
    private static function splitValues(string $tuple): array
    {
        $inner = substr($tuple, 1, -1);
        $vals = [];
        $buf = '';
        $inStr = false;
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($inStr) {
                $buf .= $ch;
                if ($ch === "'") {
                    if (($inner[$i + 1] ?? '') === "'") {
                        $buf .= "'";
                        $i++;
                    } else {
                        $inStr = false;
                    }
                }
                continue;
            }
            if ($ch === "'") {
                $inStr = true;
                $buf .= $ch;
            } elseif ($ch === ',') {
                $vals[] = trim($buf);
                $buf = '';
            } else {
                $buf .= $ch;
            }
        }
        $vals[] = trim($buf);

        $out = [];
        foreach ($vals as $v) {
            if ($v === '') {
                $out[] = '';
            } elseif ($v[0] === "'" && str_ends_with($v, "'")) {
                $out[] = str_replace("''", "'", substr($v, 1, -1));
            } elseif (strtoupper($v) === 'NULL') {
                $out[] = '';
            } else {
                $out[] = $v;
            }
        }
        return $out;
    }

    /** SQL 语句 → 物品行（字段名对齐 ITEMS_HEADERS） */
    public static function sqlToRows(array $stmts): array
    {
        $rows = [];
        foreach ($stmts as $st) {
            $row = [];
            foreach ($st['cols'] as $i => $col) {
                if (isset($st['values'][$i])) {
                    $row[$col] = $st['values'][$i];
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // ============================================================
    // 交换作业单
    // ============================================================

    /** 预览交换作业单行 */
    public static function previewExchange(array $rows): array
    {
        $out = [];
        $valid = 0;
        foreach ($rows as $i => $r) {
            $rowNo = $i + 2;
            $serial = strtoupper(clean_str($r['serial_no'] ?? ''));
            $newLoc = strtoupper(clean_str($r['new_location_code'] ?? ''));
            $errors = [];

            $item = null;
            if ($serial === '') {
                $errors[] = t('val.serialFormat');
            } else {
                $item = Items::find($serial);
                if ($item === null) {
                    $errors[] = tf('val.serialMissing', ['serial' => $serial]);
                }
            }
            if ($newLoc === '') {
                $errors[] = tf('exchange.noNewLocation', ['serial' => $serial]);
            } elseif (preg_match(Items::LOCATION_RE, $newLoc) !== 1) {
                $errors[] = tf('exchange.newLocInvalid', ['loc' => $newLoc]);
            } elseif ($newLoc === 'LTO') {
                $errors[] = '借出状态（LTO）请通过物资借还作业设置';
            }

            if (!$errors) {
                $valid++;
            }
            $out[] = [
                'row_no' => $rowNo,
                'serial_no' => $serial,
                'name' => $item['name'] ?? '',
                'location_code' => $item['location_code'] ?? '',
                'new_location_code' => $newLoc,
                'errors' => $errors,
            ];
        }
        return ['rows' => $out, 'valid' => $valid];
    }

    /** 应用交换：目前位置 = 新位置，清空新位置 */
    public static function applyExchange(array $previewRows): array
    {
        $applied = 0;
        $errors = [];
        foreach ($previewRows as $p) {
            if ($p['errors']) {
                continue;
            }
            try {
                DB::q(
                    "UPDATE items SET location_code = ?, new_location_code = '', last_modified = ? WHERE serial_no = ?",
                    [$p['new_location_code'], DB::today(), $p['serial_no']]
                );
                $applied++;
            } catch (RuntimeException $e) {
                $errors[] = '第 ' . $p['row_no'] . ' 行：' . $e->getMessage();
            }
        }
        return ['applied' => $applied, 'errors' => $errors];
    }

    // ============================================================
    // 采购清单
    // ============================================================

    /**
     * 预览采购清单：已有物品校验序列号；新增物品（序列号留空）自动生成序列号
     */
    public static function previewPurchase(array $rows): array
    {
        $out = [];
        $valid = 0;
        $autoCats = [];
        foreach ($rows as $i => $r) {
            $rowNo = $i + 2;
            $serial = strtoupper(clean_str($r['serial_no'] ?? ''));
            $qty = clean_str($r['purchase_qty'] ?? '');
            $errors = [];
            $item = null;
            $isNew = false;

            if ($serial !== '') {
                $item = Items::find($serial);
                if ($item === null) {
                    $errors[] = tf('val.serialMissing', ['serial' => $serial]);
                }
            } else {
                $isNew = true;
                $main = strtoupper(clean_str($r['main_category'] ?? ''));
                $sub = strtoupper(clean_str($r['sub_category'] ?? ''));
                $name = clean_str($r['name'] ?? '');
                if ($name === '') {
                    $errors[] = t('val.nameRequired');
                }
                if ($main === '' || $sub === '') {
                    $errors[] = t('val.categoryNeeded');
                } elseif (!isset(Serial::MAIN[$main])) {
                    $errors[] = t('val.mainInvalid');
                } elseif (preg_match('/^[A-Z]{2}$/', $sub) !== 1) {
                    $errors[] = t('val.subInvalid');
                } elseif (!Serial::subValid($main, $sub)) {
                    // 组合不存在：自动创建，不视为错误
                    Categories::ensure($main, $sub);
                    $autoCats[$main . '-' . $sub] = [
                        'main_code' => $main,
                        'sub_code' => $sub,
                        'main_name' => Serial::MAIN[$main],
                        'sub_name' => Categories::SUB_DEFAULT[$sub] ?? $sub,
                    ];
                }
            }
            if ($qty === '' || !is_natural($qty) || (int)$qty < 1) {
                $errors[] = tf('purchase.purchaseQtyInvalid', ['serial' => $serial !== '' ? $serial : ('第' . $rowNo . '行')]);
            }

            if (!$errors && $isNew) {
                try {
                    $serial = Serial::next($main, $sub);
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }

            if (!$errors) {
                $valid++;
            }
            $out[] = [
                'row_no' => $rowNo,
                'serial_no' => $serial,
                'is_new' => $isNew,
                'name' => $item['name'] ?? clean_str($r['name'] ?? ''),
                'brand' => $item['brand'] ?? clean_str($r['brand'] ?? ''),
                'main_category' => $item['main_category'] ?? strtoupper(clean_str($r['main_category'] ?? '')),
                'sub_category' => $item['sub_category'] ?? strtoupper(clean_str($r['sub_category'] ?? '')),
                'location_code' => $item['location_code'] ?? strtoupper(clean_str($r['location_code'] ?? '')),
                'unit' => $item['unit'] ?? clean_str($r['unit'] ?? ''),
                'quantity' => $item['quantity'] ?? 0,
                'purchase_qty' => (int)$qty,
                'purchase_price' => clean_str($r['purchase_price'] ?? ''),
                'notes' => clean_str($r['notes'] ?? ''),
                'errors' => $errors,
            ];
        }
        return ['rows' => $out, 'valid' => $valid, 'auto_categories' => array_values($autoCats)];
    }

    /** 应用采购：已有物品余量+=采购数量、折旧=100；新增物品入库 */
    public static function applyPurchase(array $previewRows): array
    {
        $inserted = $updated = 0;
        $errors = [];
        foreach ($previewRows as $p) {
            if ($p['errors']) {
                continue;
            }
            try {
                if ($p['is_new']) {
                    Items::create([
                        'serial_no' => $p['serial_no'],
                        'name' => $p['name'],
                        'brand' => $p['brand'],
                        'location_code' => $p['location_code'],
                        'unit' => $p['unit'],
                        'purchase_price' => $p['purchase_price'],
                        'quantity' => $p['purchase_qty'],
                        'depreciation' => 100,
                        'notes' => $p['notes'] ? ('采购入库：' . $p['notes']) : '采购入库',
                        'main_category' => $p['main_category'],
                        'sub_category' => $p['sub_category'],
                    ]);
                    $inserted++;
                } else {
                    $item = Items::find($p['serial_no']);
                    if ($item === null) {
                        $errors[] = tf('val.serialMissing', ['serial' => $p['serial_no']]);
                        continue;
                    }
                    $d = [
                        'quantity' => (int)$item['quantity'] + $p['purchase_qty'],
                        'depreciation' => 100,
                    ];
                    if ($p['purchase_price'] !== '') {
                        $d['purchase_price'] = $p['purchase_price'];
                    }
                    $r = Items::update($p['serial_no'], $d);
                    // 在备注中追加采购记录
                    $note = trim((string)$item['notes'] . "\n[采购] " . DB::today() . ' 采购 ' . $p['purchase_qty'] . (trim((string)$item['unit']) !== '' ? (' ' . $item['unit']) : ''));
                    DB::q('UPDATE items SET notes = ?, last_modified = ? WHERE serial_no = ?', [$note, DB::today(), $r['serial']]);
                    $updated++;
                }
            } catch (RuntimeException $e) {
                $errors[] = '第 ' . $p['row_no'] . ' 行：' . $e->getMessage();
            }
        }
        return ['inserted' => $inserted, 'updated' => $updated, 'errors' => $errors];
    }
}
