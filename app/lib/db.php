<?php
/**
 * PMD 数据库助手（PDO 单例）
 */
declare(strict_types=1);

class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $cfg = $GLOBALS['pmd_config']['db'] ?? [];
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $cfg['host'] ?? '127.0.0.1',
                (int)($cfg['port'] ?? 3306),
                $cfg['name'] ?? 'pmd',
                $cfg['charset'] ?? 'utf8mb4'
            );
            try {
                self::$pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                json_fail('数据库连接失败：' . $e->getMessage(), 500);
            }
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = [], $default = null)
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? $default : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    /** 今天日期 yyyy-mm-dd */
    public static function today(): string
    {
        return date('Y-m-d');
    }
}
