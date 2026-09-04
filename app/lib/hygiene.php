<?php
/**
 * PMD 卫生等级管理：预置等级、自动创建、增删改查
 *
 * 卫生等级为单个字母：预置 A/B/C/D（可在 设置→卫生等级管理 中新增/改名/删除）。
 * 导入数据遇到未登记的等级代码时自动创建（名称取自预置表，未收录时以代码本身命名）。
 */
declare(strict_types=1);

class Hygiene
{
    /**
     * 预置等级默认名称（未收录的新代码以代码本身作为名称，可在设置中修改）
     */
    public const DEFAULTS = [
        'A' => '食品接触',
        'B' => '母婴与敏感部位接触',
        'C' => '皮肤接触',
        'D' => '地面与脏污材料接触',
    ];

    /** 等级代码是否合法（单个大写字母） */
    public static function valid(string $code): bool
    {
        return preg_match('/^[A-Z]$/', $code) === 1;
    }

    /** 全部等级（按 sort_order, code 排序） */
    public static function all(): array
    {
        return DB::all('SELECT code, name, sort_order FROM hygiene_levels ORDER BY sort_order, code');
    }

    /** 某等级是否存在 */
    public static function exists(string $code): bool
    {
        return DB::one('SELECT 1 FROM hygiene_levels WHERE code = ?', [$code]) !== null;
    }

    /**
     * 确保等级存在：不存在则自动创建。
     * @return bool true=本次新建，false=已存在
     */
    public static function ensure(string $code): bool
    {
        $code = strtoupper($code);
        if (!self::valid($code)) {
            return false;
        }
        if (self::exists($code)) {
            return false;
        }
        $name = self::DEFAULTS[$code] ?? $code;
        DB::q('INSERT IGNORE INTO hygiene_levels (code, name, sort_order) VALUES (?,?,?)', [$code, $name, 0]);
        return true;
    }
}
