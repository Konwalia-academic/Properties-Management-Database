<?php
/**
 * PMD 三大作业流：物资交换、物资采购、物资借还
 */
declare(strict_types=1);

class Workflows
{
    // ============================================================
    // 会话草稿（作业单确认前暂存）
    // ============================================================

    public static function draftSave(string $type, array $rows): string
    {
        if (!isset($_SESSION['pmd_drafts']) || !is_array($_SESSION['pmd_drafts'])) {
            $_SESSION['pmd_drafts'] = [];
        }
        // 清理过期草稿（24h）与超量草稿
        $now = time();
        foreach ($_SESSION['pmd_drafts'] as $k => $d) {
            if ($now - ($d['ts'] ?? 0) > 86400 || count($_SESSION['pmd_drafts']) > 20) {
                unset($_SESSION['pmd_drafts'][$k]);
            }
        }
        $token = bin2hex(random_bytes(8));
        $_SESSION['pmd_drafts'][$token] = ['type' => $type, 'rows' => $rows, 'ts' => $now];
        return $token;
    }

    public static function draftGet(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }
        return $_SESSION['pmd_drafts'][$token] ?? null;
    }

    public static function draftClear(string $token): void
    {
        unset($_SESSION['pmd_drafts'][$token]);
    }

    // ============================================================
    // 物资交换作业
    // ============================================================

    /** 步骤2：批量设置"新所在位置代码" */
    public static function exchangePrepare(array $serials, string $newLoc): array
    {
        $newLoc = strtoupper(clean_str($newLoc));
        if ($newLoc === '' || preg_match(Items::LOCATION_RE, $newLoc) !== 1) {
            throw new RuntimeException(t('val.locationInvalid'));
        }
        if ($newLoc === 'LTO') {
            throw new RuntimeException('借出状态（LTO）请通过物资借还作业设置');
        }
        $applied = 0;
        $errors = [];
        foreach (array_unique($serials) as $s) {
            $s = strtoupper(clean_str($s));
            $item = Items::find($s);
            if ($item === null) {
                $errors[] = tf('val.serialMissing', ['serial' => $s]);
                continue;
            }
            DB::q("UPDATE items SET new_location_code = ?, last_modified = ? WHERE serial_no = ?", [$newLoc, DB::today(), $s]);
            $applied++;
        }
        return ['applied' => $applied, 'errors' => $errors];
    }

    /** 待交换物品（新位置已填写） */
    public static function exchangePending(): array
    {
        return DB::all(
            "SELECT serial_no, name, location_code, new_location_code, notes
             FROM items
             WHERE new_location_code <> '' AND main_category <> 'B'
             ORDER BY new_location_code, serial_no"
        );
    }

    /** 步骤3：由待交换物品生成作业单草稿 */
    public static function exchangeGenerate(): array
    {
        $pending = self::exchangePending();
        $rows = [];
        foreach ($pending as $p) {
            $rows[] = [
                'serial_no' => $p['serial_no'],
                'name' => $p['name'],
                'location_code' => $p['location_code'],
                'new_location_code' => $p['new_location_code'],
                'notes' => $p['notes'],
                'errors' => [],
            ];
        }
        $token = self::draftSave('exchange', $rows);
        return ['token' => $token, 'rows' => $rows];
    }

    /** 步骤5：应用交换修改 */
    public static function exchangeApply(string $token): array
    {
        $draft = self::draftGet($token);
        if ($draft === null || ($draft['type'] ?? '') !== 'exchange') {
            throw new RuntimeException('作业单草稿不存在或已过期，请重新生成');
        }
        $result = Importer::applyExchange($draft['rows']);
        self::draftClear($token);
        return $result;
    }

    // ============================================================
    // 物资采购作业
    // ============================================================

    /** 步骤1-2：检索区域中折旧 < 20% 的物品 */
    public static function purchaseScan(string $location): array
    {
        $where = "i.depreciation < 20 AND i.main_category <> 'B' AND i.location_code <> 'LTO'";
        $params = [];
        $location = strtoupper(clean_str($location));
        if ($location !== '') {
            $where .= ' AND i.location_code = ?';
            $params[] = $location;
        }
        return DB::all(
            "SELECT i.serial_no, i.name, i.brand, i.location_code, i.unit, i.quantity,
                    i.depreciation, i.purchase_price, i.main_category, i.sub_category,
                    c.main_name, c.sub_name
             FROM items i
             LEFT JOIN categories c ON c.main_code = i.main_category AND c.sub_code = i.sub_category
             WHERE $where
             ORDER BY i.depreciation ASC, i.serial_no",
            $params
        );
    }

    /** 步骤4-6：生成欲购清单草稿（新增物品自动生成序列号） */
    public static function purchaseGenerate(array $rows): array
    {
        $preview = Importer::previewPurchase($rows);
        $invalid = array_values(array_filter($preview['rows'], fn($r) => !empty($r['errors'])));
        if ($invalid) {
            return ['ok' => false, 'rows' => $preview['rows']];
        }
        $token = self::draftSave('purchase', $preview['rows']);
        return ['ok' => true, 'token' => $token, 'rows' => $preview['rows']];
    }

    /** 步骤7：应用采购修改 */
    public static function purchaseApply(string $token): array
    {
        $draft = self::draftGet($token);
        if ($draft === null || ($draft['type'] ?? '') !== 'purchase') {
            throw new RuntimeException('欲购清单草稿不存在或已过期，请重新生成');
        }
        $result = Importer::applyPurchase($draft['rows']);
        self::draftClear($token);
        return $result;
    }

    // ============================================================
    // 物资借还作业
    // ============================================================

    /** 借出：位置改为 LTO，备注记录借用人，写入借还记录 */
    public static function borrowOut(array $serials, string $borrower): array
    {
        $borrower = clean_str($borrower);
        if ($borrower === '') {
            throw new RuntimeException(t('borrow.noBorrower'));
        }
        $applied = 0;
        $errors = [];
        $today = DB::today();
        foreach (array_unique($serials) as $s) {
            $s = strtoupper(clean_str($s));
            $item = Items::find($s);
            if ($item === null) {
                $errors[] = tf('val.serialMissing', ['serial' => $s]);
                continue;
            }
            if ($item['location_code'] === 'LTO') {
                $errors[] = $s . ' 已在借出中';
                continue;
            }
            $note = trim((string)$item['notes'] . "\n[借出] " . $today . ' 借出给 ' . $borrower);
            DB::q("UPDATE items SET location_code = 'LTO', notes = ?, last_modified = ? WHERE serial_no = ?", [$note, $today, $s]);
            DB::q('INSERT INTO borrow_log (serial_no, borrower, borrowed_at, note) VALUES (?,?,?,?)', [$s, $borrower, $today, '借出给 ' . $borrower]);
            $applied++;
        }
        return ['applied' => $applied, 'errors' => $errors];
    }

    /** 借出中物品（含借用人信息） */
    public static function borrowList(): array
    {
        return DB::all(
            "SELECT i.serial_no, i.name, i.brand, i.unit, i.quantity, i.depreciation, i.notes,
                    b.borrower, b.borrowed_at, b.id AS log_id
             FROM items i
             LEFT JOIN borrow_log b ON b.serial_no = i.serial_no AND b.returned_at IS NULL
             WHERE i.location_code = 'LTO'
             ORDER BY b.borrowed_at DESC, i.serial_no"
        );
    }

    /** 归还：更新余量/折旧/位置，备注追加归还记录 */
    public static function borrowIn(string $serial, float $qty, int $dep, string $loc, string $note = ''): array
    {
        $serial = strtoupper(clean_str($serial));
        $item = Items::find($serial);
        if ($item === null) {
            throw new RuntimeException(tf('val.serialMissing', ['serial' => $serial]));
        }
        if ($qty < 0) {
            throw new RuntimeException(t('items.quantityWarn'));
        }
        if ($dep < 0 || $dep > 100) {
            throw new RuntimeException(t('items.depWarn'));
        }
        $loc = strtoupper(clean_str($loc));
        if (preg_match(Items::LOCATION_RE, $loc) !== 1) {
            throw new RuntimeException(t('val.locationInvalid'));
        }
        if ($loc === 'LTO') {
            throw new RuntimeException('归还位置不能为 LTO');
        }

        $today = DB::today();
        $note = clean_str($note);
        $append = "\n[归还] " . $today . ' 归还，余量 ' . $qty . '，折旧 ' . $dep . '%' . ($note !== '' ? ('（' . $note . '）') : '');
        DB::q(
            "UPDATE items SET location_code = ?, quantity = ?, depreciation = ?, notes = CONCAT(notes, ?), last_modified = ? WHERE serial_no = ?",
            [$loc, $qty, $dep, $append, $today, $serial]
        );
        DB::q(
            "UPDATE borrow_log SET returned_at = ? WHERE serial_no = ? AND returned_at IS NULL",
            [$today, $serial]
        );
        return ['serial' => $serial];
    }

    /** 借还记录 */
    public static function borrowHistory(int $limit = 200): array
    {
        return DB::all(
            "SELECT b.*, i.name AS item_name
             FROM borrow_log b
             LEFT JOIN items i ON i.serial_no = b.serial_no
             ORDER BY b.borrowed_at DESC, b.id DESC
             LIMIT " . min(500, max(1, $limit))
        );
    }
}
