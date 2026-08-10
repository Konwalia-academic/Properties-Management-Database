<?php
/**
 * PMD 物品 CRUD 与检索
 */
declare(strict_types=1);

class Items
{
    public const FIELDS = [
        'serial_no', 'name', 'brand', 'location_code', 'new_location_code',
        'container_serial', 'purchase_price', 'quantity', 'quarterly_consumption',
        'unit', 'depreciation', 'notes', 'main_category', 'sub_category', 'barcode',
    ];

    public const LOCATION_RE = '/^[A-Za-z]{2,4}$/';

    /**
     * 规范化并校验输入（不涉及序列号唯一性/生成）
     * @return array [data, warnings[]]
     * @throws RuntimeException
     */
    public static function normalize(array $d, bool $requireName = true): array
    {
        $warnings = [];

        $name = clean_str($d['name'] ?? '');
        if ($requireName && $name === '') {
            throw new RuntimeException(t('val.nameRequired'));
        }

        $data = [
            'name'                  => $name,
            'brand'                 => clean_str($d['brand'] ?? ''),
            'location_code'         => strtoupper(clean_str($d['location_code'] ?? '')),
            'new_location_code'     => strtoupper(clean_str($d['new_location_code'] ?? '')),
            'container_serial'      => strtoupper(clean_str($d['container_serial'] ?? '')),
            'unit'                  => clean_str($d['unit'] ?? ''),
            'notes'                 => clean_str($d['notes'] ?? ''),
            'barcode'               => clean_str($d['barcode'] ?? ''),
        ];

        foreach (['location_code', 'new_location_code'] as $k) {
            if ($data[$k] !== '' && preg_match(self::LOCATION_RE, $data[$k]) !== 1) {
                throw new RuntimeException(t('val.locationInvalid'));
            }
        }

        if ($data['container_serial'] !== '') {
            if (preg_match('/^[A-Z]{3}[0-9]{3}$/', $data['container_serial']) !== 1) {
                throw new RuntimeException(t('val.containerInvalid'));
            }
            $c = DB::one('SELECT serial_no, main_category FROM items WHERE serial_no = ?', [$data['container_serial']]);
            if ($c === null) {
                $warnings[] = '所在容器序列号 ' . $data['container_serial'] . ' 在数据库中不存在（已记录，请核实）';
            } elseif ($c['main_category'] !== 'R') {
                $warnings[] = '所在容器序列号 ' . $data['container_serial'] . ' 的母类别不是 R（容器）';
            }
        }

        // 价格
        $price = clean_str($d['purchase_price'] ?? '');
        $data['purchase_price'] = ($price === '') ? 0 : (float)$price;
        if ($data['purchase_price'] < 0) {
            throw new RuntimeException('购入价格不能为负数');
        }

        // 余量（支持小数）/ 季度消耗量（仅整数）
        $qty = clean_str($d['quantity'] ?? '0');
        if ($qty === '') $qty = '0';
        if (!is_non_negative_num($qty)) {
            throw new RuntimeException(t('items.quantityWarn'));
        }
        $data['quantity'] = (float)$qty;

        $qc = clean_str($d['quarterly_consumption'] ?? '0');
        if ($qc === '') $qc = '0';
        if (!is_non_negative_num($qc)) {
            throw new RuntimeException('季度消耗量无效，必须为非负数字（支持小数）');
        }
        $data['quarterly_consumption'] = (float)$qc;

        // 折旧 0-100
        $dep = clean_str($d['depreciation'] ?? '100');
        if ($dep === '') $dep = '100';
        if (!is_natural($dep) || (int)$dep > 100) {
            throw new RuntimeException(t('items.depWarn'));
        }
        $data['depreciation'] = (int)$dep;

        // 类别
        $main = strtoupper(clean_str($d['main_category'] ?? ''));
        $sub = strtoupper(clean_str($d['sub_category'] ?? ''));
        if ($main === '' && $sub === '') {
            // 从已有序列号推导（更新场景）
            $data['main_category'] = '';
            $data['sub_category'] = '';
        } else {
            if (!isset(Serial::MAIN[$main])) {
                throw new RuntimeException(t('val.mainInvalid'));
            }
            if (!preg_match('/^[A-Z]{2}$/', $sub)) {
                throw new RuntimeException(t('val.subInvalid'));
            }
            // 类别组合不存在时自动创建（导入/新增/批量修改均适用）
            Categories::ensure($main, $sub);
            $data['main_category'] = $main;
            $data['sub_category'] = $sub;
        }

        return [$data, $warnings];
    }

    /** 序列号字段归属（供表单/导入用） */
    public static function applySerial(array $data, ?string $serial): array
    {
        $serial = strtoupper(clean_str($serial ?? ''));
        if ($serial === '') {
            if ($data['main_category'] === '' || $data['sub_category'] === '') {
                throw new RuntimeException(t('val.categoryNeeded'));
            }
            $serial = Serial::next($data['main_category'], $data['sub_category']);
        } else {
            // 序列号与类别一致性：以序列号为准；组合不存在时自动创建
            $data['main_category'] = $serial[0];
            $data['sub_category'] = substr($serial, 1, 2);
            Categories::ensure($data['main_category'], $data['sub_category']);
            Serial::assertUsable($serial);
        }
        return [$serial, $data];
    }

    public static function create(array $d): array
    {
        [$data, $warnings] = self::normalize($d);
        [$serial, $data] = self::applySerial($data, $d['serial_no'] ?? null);

        DB::q(
            'INSERT INTO items
             (serial_no, name, brand, location_code, new_location_code, container_serial,
              purchase_price, quantity, quarterly_consumption, unit, depreciation,
              notes, main_category, sub_category, barcode, last_modified)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $serial, $data['name'], $data['brand'], $data['location_code'],
                $data['new_location_code'], $data['container_serial'], $data['purchase_price'],
                $data['quantity'], $data['quarterly_consumption'], $data['unit'],
                $data['depreciation'], $data['notes'], $data['main_category'],
                $data['sub_category'], $data['barcode'], DB::today(),
            ]
        );
        return ['serial' => $serial, 'warnings' => $warnings];
    }

    /**
     * 更新物品（合并式：仅更新传入的字段，未传字段保持原值）。
     * 若变更母/子类别，序列号自动重新生成。
     * @return array ['serial' => 当前序列号(可能已变), 'old_serial' => 原序列号, 'warnings' => []]
     */
    public static function update(string $serial, array $d): array
    {
        $serial = strtoupper(clean_str($serial));
        $item = self::find($serial);
        if ($item === null) {
            throw new RuntimeException(tf('val.serialMissing', ['serial' => $serial]));
        }

        // 合并：以传入字段覆盖原记录，未传入的保持原值
        $merged = $item;
        foreach ($d as $k => $v) {
            if (in_array($k, self::FIELDS, true)) {
                $merged[$k] = $v;
            }
        }
        if (clean_str($merged['name'] ?? '') === '') {
            throw new RuntimeException(t('val.nameRequired'));
        }

        [$data, $warnings] = self::normalize($merged, requireName: false);
        $oldMain = $item['main_category'];
        $oldSub = $item['sub_category'];
        $newMain = $data['main_category'] !== '' ? $data['main_category'] : $oldMain;
        $newSub = $data['sub_category'] !== '' ? $data['sub_category'] : $oldSub;
        $data['main_category'] = $newMain;
        $data['sub_category'] = $newSub;

        $newSerial = $serial;
        $oldSerial = $serial;
        if ($newMain !== $oldMain || $newSub !== $oldSub) {
            $newSerial = Serial::next($newMain, $newSub);
            $oldSerial = $serial;
        }

        // 若请求中显式提供了不同序列号，校验可用性（用于"修改序列号"场景）
        $reqSerial = strtoupper(clean_str($d['serial_no'] ?? ''));
        if ($reqSerial !== '' && $reqSerial !== $serial) {
            Serial::assertUsable($reqSerial, $serial);
            $newSerial = $reqSerial;
            $data['main_category'] = $reqSerial[0];
            $data['sub_category'] = substr($reqSerial, 1, 2);
        }

        DB::q(
            'UPDATE items SET
               serial_no = ?, name = ?, brand = ?, location_code = ?,
               new_location_code = ?, container_serial = ?, purchase_price = ?,
               quantity = ?, quarterly_consumption = ?, unit = ?, depreciation = ?,
               notes = ?, main_category = ?, sub_category = ?, barcode = ?,
               last_modified = ?
             WHERE serial_no = ?',
            [
                $newSerial, $data['name'], $data['brand'], $data['location_code'],
                $data['new_location_code'], $data['container_serial'], $data['purchase_price'],
                $data['quantity'], $data['quarterly_consumption'], $data['unit'],
                $data['depreciation'], $data['notes'], $data['main_category'],
                $data['sub_category'], $data['barcode'], DB::today(), $serial,
            ]
        );

        // 序列号变更后，同步引用它的"所在容器序列号"
        if ($newSerial !== $oldSerial) {
            DB::q('UPDATE items SET container_serial = ?, last_modified = ? WHERE container_serial = ?', [$newSerial, DB::today(), $oldSerial]);
        }

        return ['serial' => $newSerial, 'old_serial' => $oldSerial, 'warnings' => $warnings];
    }

    public static function delete(string $serial): void
    {
        $serial = strtoupper(clean_str($serial));
        DB::exec('DELETE FROM items WHERE serial_no = ?', [$serial]);
        // 引用清理：容器字段置空
        DB::q('UPDATE items SET container_serial = \'\' WHERE container_serial = ?', [$serial]);
    }

    public static function find(string $serial): ?array
    {
        return DB::one(
            'SELECT i.*, c.main_name, c.sub_name
             FROM items i
             LEFT JOIN categories c ON c.main_code = i.main_category AND c.sub_code = i.sub_category
             WHERE i.serial_no = ?',
            [$serial]
        );
    }

    public static function touch(string $serial): void
    {
        DB::q('UPDATE items SET last_modified = ? WHERE serial_no = ?', [DB::today(), $serial]);
    }

    /**
     * 检索
     * filters: q, location, main_category, sub_category, show_scrapped(bool), pending_only(bool), page, page_size, sort, order
     */
    public static function search(array $f): array
    {
        $where = [];
        $params = [];

        $q = clean_str($f['q'] ?? '');
        if ($q !== '') {
            $where[] = '(i.serial_no LIKE ? OR i.name LIKE ? OR i.brand LIKE ?
                         OR i.barcode LIKE ? OR i.notes LIKE ? OR i.location_code LIKE ?
                         OR i.container_serial LIKE ?)';
            $like = '%' . $q . '%';
            for ($i = 0; $i < 7; $i++) {
                $params[] = $like;
            }
        }

        $loc = strtoupper(clean_str($f['location'] ?? ''));
        if ($loc !== '') {
            $where[] = 'i.location_code = ?';
            $params[] = $loc;
        }

        $main = strtoupper(clean_str($f['main_category'] ?? ''));
        if ($main !== '') {
            $where[] = 'i.main_category = ?';
            $params[] = $main;
        }

        $sub = strtoupper(clean_str($f['sub_category'] ?? ''));
        if ($sub !== '') {
            $where[] = 'i.sub_category = ?';
            $params[] = $sub;
        }

        if (empty($f['show_scrapped'])) {
            $where[] = "i.main_category <> 'B'";
        }

        if (!empty($f['pending_only'])) {
            $where[] = "i.new_location_code <> ''";
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // 排序
        $sortMap = [
            'serial_no' => 'i.serial_no', 'name' => 'i.name', 'location_code' => 'i.location_code',
            'quantity' => 'i.quantity', 'depreciation' => 'i.depreciation', 'last_modified' => 'i.last_modified',
            'purchase_price' => 'i.purchase_price',
        ];
        $sort = $sortMap[$f['sort'] ?? ''] ?? 'i.last_modified';
        $order = strtoupper($f['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $total = (int)DB::val("SELECT COUNT(*) FROM items i $whereSql", $params);

        $page = max(1, (int)($f['page'] ?? 1));
        $pageSize = min(500, max(1, (int)($f['page_size'] ?? (int)Settings::get('rows_per_page', 50))));
        if (!empty($f['all'])) {
            $pageSize = 1000000; // 导出用：不分页
        }
        $offset = ($page - 1) * $pageSize;

        $rows = DB::all(
            "SELECT i.*, c.main_name, c.sub_name
             FROM items i
             LEFT JOIN categories c ON c.main_code = i.main_category AND c.sub_code = i.sub_category
             $whereSql ORDER BY $sort $order, i.serial_no ASC LIMIT $pageSize OFFSET $offset",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize];
    }

    /**
     * 批量修改（多选 + 单字段）
     * @return array ['updated' => n, 'changed' => [old=>new] 序列号变更映射, 'errors' => []]
     */
    public static function batchUpdate(array $serials, string $field, $value): array
    {
        $serials = array_values(array_unique(array_map(fn($s) => strtoupper(clean_str($s)), $serials)));
        if (!$serials) {
            throw new RuntimeException(t('val.batchEmpty'));
        }
        if (!in_array($field, self::FIELDS, true) || $field === 'serial_no') {
            throw new RuntimeException('该字段不支持批量修改');
        }

        $updated = 0;
        $changed = [];
        $errors = [];
        foreach ($serials as $s) {
            try {
                $d = [$field => $value];
                if ($field === 'main_category' || $field === 'sub_category') {
                    // 类别批量修改：需要两者配合，因此单独处理
                    $item = self::find($s);
                    if ($item === null) {
                        $errors[] = tf('val.serialMissing', ['serial' => $s]);
                        continue;
                    }
                    $newMain = $field === 'main_category' ? strtoupper(clean_str((string)$value)) : $item['main_category'];
                    $newSub = $field === 'sub_category' ? strtoupper(clean_str((string)$value)) : $item['sub_category'];
                    $r = self::update($s, ['main_category' => $newMain, 'sub_category' => $newSub]);
                    if ($r['serial'] !== $s) {
                        $changed[$s] = $r['serial'];
                    }
                } else {
                    $r = self::update($s, $d);
                    if ($r['serial'] !== $s) {
                        $changed[$s] = $r['serial'];
                    }
                }
                $updated++;
            } catch (RuntimeException $e) {
                $errors[] = $s . ': ' . $e->getMessage();
            }
        }
        return ['updated' => $updated, 'changed' => $changed, 'errors' => $errors];
    }
}
