<?php
/**
 * PMD 多语言接口
 *
 * 新增语言步骤（详见 readme.md）：
 *   1. 复制 app/lang/zh-CN.php 为 app/lang/en-US.php 并翻译数组内容；
 *   2. 在下方 LANGUAGES 中注册该语言；
 *   3. 设置页语言下拉框会自动出现新选项。
 */
declare(strict_types=1);

class I18n
{
    public const LANGUAGES = [
        'zh-CN' => '简体中文',
    ];

    private static string $lang = 'zh-CN';
    private static array $strings = [];

    public static function init(): void
    {
        $wanted = 'zh-CN';
        if (PMD_INSTALLED) {
            try {
                $wanted = (string)Settings::get('language', 'zh-CN');
            } catch (\Throwable $e) {
                $wanted = 'zh-CN';
            }
        }
        if (!isset(self::LANGUAGES[$wanted])) {
            $wanted = 'zh-CN';
        }
        self::$lang = $wanted;
        self::$strings = self::load($wanted);
    }

    public static function load(string $code): array
    {
        $file = PMD_LANG . '/' . $code . '.php';
        if (is_file($file)) {
            $arr = require $file;
            return is_array($arr) ? $arr : [];
        }
        return [];
    }

    public static function lang(): string
    {
        return self::$lang;
    }

    public static function t(string $key): string
    {
        return self::$strings[$key] ?? $key;
    }

    public static function all(): array
    {
        return self::$strings;
    }
}
