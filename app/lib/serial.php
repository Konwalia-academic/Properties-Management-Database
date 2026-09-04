<?php
/**
 * PMD 序列号工具
 *
 * 序列号规范：
 *   - 共 6 位：3 位字母 + 3 位十进制数字，如 NDZ121
 *   - 第 1 位字母 = 母类别：R=容器 N=耐用品 H=消耗品 B=已报废
 *   - 第 2-3 位字母 = 子类别：BG/QJ/RH/SN/WJ/DZ/FS/YP/SP/CJ/YS/GH/ZS/YD/AQ/FZ 等
 *   - 后 3 位数字在前序字母（3 位前缀）不同的情况下可重复；
 *     前缀相同时不可重复。新增时自动递增或占用不重复的数字组合。
 */
declare(strict_types=1);

class Serial
{
    public const MAIN = ['R' => '容器', 'N' => '耐用品', 'H' => '消耗品', 'B' => '已报废'];

    /** 校验序列号基本格式（不含库内唯一性） */
    public static function valid(string $serial): bool
    {
        return preg_match('/^[A-Z]{3}[0-9]{3}$/', $serial) === 1
            && isset(self::MAIN[$serial[0]]);
    }

    /** 校验子类别代码是否在类别表中（针对给定母类别） */
    public static function subValid(string $main, string $sub): bool
    {
        $row = DB::one('SELECT 1 FROM categories WHERE main_code = ? AND sub_code = ?', [$main, $sub]);
        return $row !== null;
    }

    /** 前缀 = 母类别 + 子类别，如 N + DZ = NDZ */
    public static function prefix(string $main, string $sub): string
    {
        return strtoupper($main[0] . substr($sub, 0, 2));
    }

    /** 拆分序列号 → [main, sub, num]；无效返回 null */
    public static function split(string $serial): ?array
    {
        if (!self::valid($serial)) {
            return null;
        }
        return [
            'main' => $serial[0],
            'sub'  => substr($serial, 1, 2),
            'num'  => (int)substr($serial, 3, 3),
        ];
    }

    /**
     * 获取某前缀下全部已占用的数字后缀
     * @return int[]
     */
    public static function usedNumbers(string $prefix): array
    {
        $rows = DB::all("SELECT serial_no FROM items WHERE serial_no LIKE ?", [$prefix . '%']);
        $used = [];
        foreach ($rows as $r) {
            $n = (int)substr($r['serial_no'], 3, 3);
            $used[$n] = true;
        }
        return array_keys($used);
    }

    /**
     * 生成下一个可用序列号：优先 max+1 自动递增；
     * 若已达 999，则占用最小空闲数字组合；全部占满则抛异常。
     */
    public static function next(string $main, string $sub): string
    {
        $main = strtoupper($main);
        $sub = strtoupper($sub);
        if (!isset(self::MAIN[$main])) {
            throw new RuntimeException(t('val.mainInvalid'));
        }
        if (!self::subValid($main, $sub)) {
            throw new RuntimeException(tf('val.categoryInvalid', ['main' => $main, 'sub' => $sub]));
        }

        $prefix = self::prefix($main, $sub);
        $used = self::usedNumbers($prefix);
        $usedMap = array_fill_keys($used, true);

        if (count($used) >= 999) {
            throw new RuntimeException(t('val.prefixFull'));
        }

        // 自动递增：max+1
        if ($used) {
            $max = max($used);
            if ($max < 999 && !isset($usedMap[$max + 1])) {
                return $prefix . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
            }
        } else {
            return $prefix . '001';
        }

        // 占用最小空闲组合
        for ($i = 1; $i <= 999; $i++) {
            if (!isset($usedMap[$i])) {
                return $prefix . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
            }
        }
        throw new RuntimeException(t('val.prefixFull'));
    }

    /** 校验手动输入的序列号：格式 + 类别合法 + 库内唯一（可忽略某条自身） */
    public static function assertUsable(string $serial, ?string $ignoreSerial = null): void
    {
        $serial = strtoupper(clean_str($serial));
        if (!self::valid($serial)) {
            throw new RuntimeException(t('val.serialFormat'));
        }
        $main = $serial[0];
        $sub = substr($serial, 1, 2);
        if (!self::subValid($main, $sub)) {
            throw new RuntimeException(tf('val.categoryInvalid', ['main' => $main, 'sub' => $sub]));
        }
        $exists = DB::one('SELECT serial_no FROM items WHERE serial_no = ?', [$serial]);
        if ($exists && $exists['serial_no'] !== $ignoreSerial) {
            throw new RuntimeException(tf('val.serialExist', ['serial' => $serial]));
        }
    }
}
