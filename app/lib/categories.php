<?php
/**
 * PMD 类别管理：自动创建、默认子类别名称、乱码自愈
 */
declare(strict_types=1);

class Categories
{
    /**
     * 预置子类别默认名称（未收录的新代码以代码本身作为名称，可在设置中修改）
     */
    public const SUB_DEFAULT = [
        'BG' => '办公用品',
        'QJ' => '卫生清洁用品',
        'RH' => '日化用品',
        'SN' => '收纳用品',
        'WJ' => '五金工具',
        'DZ' => '电子设备',
        'FS' => '服饰品',
        'YP' => '药品',
        'SP' => '食品饮品',
        'CJ' => '餐厨用具',
        'YS' => '印刷品',
        'GH' => '个人护理用品',
        'ZS' => '装饰品',
        'YD' => '运动器械',
        'AQ' => '安全设施',
        'FZ' => '其他纺织品',
    ];

    /**
     * 确保类别组合存在：不存在则自动创建（导入/新增/修改时调用）。
     * @return bool true=本次新建，false=已存在
     */
    public static function ensure(string $main, string $sub): bool
    {
        $main = strtoupper($main);
        $sub = strtoupper($sub);
        if (!isset(Serial::MAIN[$main]) || preg_match('/^[A-Z]{2}$/', $sub) !== 1) {
            return false;
        }
        if (DB::one('SELECT 1 FROM categories WHERE main_code = ? AND sub_code = ?', [$main, $sub])) {
            return false;
        }
        $subName = self::SUB_DEFAULT[$sub] ?? $sub;
        DB::q(
            'INSERT IGNORE INTO categories (main_code, sub_code, main_name, sub_name) VALUES (?,?,?,?)',
            [$main, $sub, Serial::MAIN[$main], $subName]
        );
        return true;
    }

    /**
     * Windows-1252 字节 → Unicode 码点映射（0x81/0x8D/0x8F/0x90/0x9D 未定义）
     */
    private const CP1252 = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026,
        0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160,
        0x8B => 0x2039, 0x8C => 0x0152, 0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019,
        0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
        0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A, 0x9C => 0x0153,
        0x9E => 0x017E, 0x9F => 0x0178,
    ];

    /**
     * 判断字符串是否为「UTF-8 被当作 cp1252/latin1 二次编码」的乱码。
     * 兼容两种形态：
     *   A. 合法 UTF-8，含 ä/å/æ/ç/è/é 等拉丁扩展字符（正常中文不会出现）；
     *   B. 非法 UTF-8（如含 cp1252 未定义字节 0x81 的原始字节）。
     */
    public static function isDoubleEncoded(string $s): bool
    {
        if ($s === '') {
            return false;
        }
        $validUtf8 = mb_check_encoding($s, 'UTF-8');
        $sig = $validUtf8 && preg_match('/[äåæçèé]/u', $s) === 1;
        $hasHigh = preg_match('/[\x80-\xFF]/', $s) === 1;
        if (!$sig && ($validUtf8 || !$hasHigh)) {
            return false;
        }
        return self::reverseCp1252($s) !== null;
    }

    /** 修复单个乱码字符串；非乱码原样返回 */
    public static function fixDoubleEncoded(string $s): string
    {
        $r = self::reverseCp1252($s);
        return $r ?? $s;
    }

    /**
     * 字节级逆向转换：把存储串按 UTF-8 解码后逐字符映射回 cp1252 字节，
     * 重组得到的字节流必须是合法 UTF-8 且含 CJK 汉字才判定为修复成功。
     */
    private static function reverseCp1252(string $s): ?string
    {
        static $rev = null;
        if ($rev === null) {
            $rev = [];
            for ($b = 0xA0; $b <= 0xFF; $b++) {
                $rev[$b] = chr($b); // A0-FF 与 Unicode 码点一致
            }
            foreach (self::CP1252 as $byte => $cp) {
                $rev[$cp] = chr($byte);
            }
            // cp1252 未定义字节（0x81/0x8D/0x8F/0x90/0x9D）经 latin1/cp1252 转换后
            // 会以 C1 控制符 U+0080-U+009F 形式存储（UTF-8: C2 80-C2 9F），逆向还原为原字节
            for ($cp = 0x80; $cp <= 0x9F; $cp++) {
                if (!isset($rev[$cp])) {
                    $rev[$cp] = chr($cp);
                }
            }
        }
        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $byte = ord($s[$i]);
            if ($byte < 0x80) {
                $out .= $s[$i];
                $i++;
                continue;
            }
            // 尝试解析一个 UTF-8 字符
            $n = 0;
            if (($byte & 0xE0) === 0xC0) {
                $n = 1;
            } elseif (($byte & 0xF0) === 0xE0) {
                $n = 2;
            } elseif (($byte & 0xF8) === 0xF0) {
                $n = 3;
            }
            if ($n > 0 && $i + $n < $len) {
                $seq = substr($s, $i, $n + 1);
                if (@mb_check_encoding($seq, 'UTF-8')) {
                    $cp = @mb_ord($seq, 'UTF-8');
                    if (isset($rev[$cp])) {
                        $out .= $rev[$cp];
                        $i += $n + 1;
                        continue;
                    }
                }
            }
            // 非法/无法映射的字节原样保留（本身就是 cp1252 字节，如 0x81）
            $out .= $s[$i];
            $i++;
        }
        if (!mb_check_encoding($out, 'UTF-8')) {
            return null;
        }
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $out) !== 1) {
            return null;
        }
        return $out;
    }

    /**
     * 扫描并修复全库文本字段中的中文乱码（UTF-8 二次编码）。
     * @return array{scanned:int, fixed:int, tables:array<string,int>}
     */
    public static function repairCharset(): array
    {
        $result = ['scanned' => 0, 'fixed' => 0, 'tables' => []];
        // [表名, 主键, 文本字段列表]
        $targets = [
            ['categories', 'main_code, sub_code', ['main_name', 'sub_name']],
            ['locations',  'code',                ['name']],
            ['settings',   'skey',                ['svalue']],
            ['items',      'serial_no',           ['name', 'brand', 'unit', 'notes']],
            ['borrow_log', 'id',                  ['borrower', 'note']],
        ];
        foreach ($targets as [$table, $pk, $fields]) {
            $rows = DB::all("SELECT $pk, " . implode(', ', $fields) . " FROM $table");
            foreach ($rows as $row) {
                foreach ($fields as $f) {
                    $v = (string)($row[$f] ?? '');
                    if ($v === '' || !self::isDoubleEncoded($v)) {
                        continue;
                    }
                    $fixed = self::fixDoubleEncoded($v);
                    $where = [];
                    $params = [];
                    foreach (explode(', ', $pk) as $k) {
                        $where[] = "$k = ?";
                        $params[] = $row[$k];
                    }
                    DB::q("UPDATE $table SET $f = ? WHERE " . implode(' AND ', $where), [$fixed, ...$params]);
                    $result['fixed']++;
                    $result['tables'][$table] = ($result['tables'][$table] ?? 0) + 1;
                }
                $result['scanned']++;
            }
        }
        return $result;
    }
}
