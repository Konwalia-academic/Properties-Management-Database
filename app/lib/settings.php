<?php
/**
 * PMD 系统设置
 */
declare(strict_types=1);

class Settings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (DB::all('SELECT skey, svalue FROM settings') as $r) {
                self::$cache[$r['skey']] = $r['svalue'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        DB::q(
            'INSERT INTO settings (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
            [$key, (string)$value]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = (string)$value;
        }
    }
}
