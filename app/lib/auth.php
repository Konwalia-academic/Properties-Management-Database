<?php
/**
 * PMD 登录认证（单一 PIN）
 */
declare(strict_types=1);

class Auth
{
    private const SESSION_KEY = 'pmd_auth';

    public static function logged(): bool
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function requireLogin(): void
    {
        if (!self::logged()) {
            json_fail('未登录或会话已过期', 401);
        }
    }

    /** 尝试登录；带简单的失败限速（5次失败锁60秒） */
    public static function attempt(string $pin): bool
    {
        $now = time();
        $fails = (int)($_SESSION['pmd_login_fails'] ?? 0);
        $lockUntil = (int)($_SESSION['pmd_login_lock'] ?? 0);

        if ($now < $lockUntil) {
            return false;
        }
        if ($fails >= 5) {
            $_SESSION['pmd_login_lock'] = $now + 60;
            $_SESSION['pmd_login_fails'] = 0;
            return false;
        }

        $hash = (string)Settings::get('pin_hash', '');
        if ($hash !== '' && password_verify($pin, $hash)) {
            session_regenerate_id(true);
            $_SESSION[self::SESSION_KEY] = true;
            $_SESSION['pmd_login_fails'] = 0;
            $_SESSION['pmd_login_lock'] = 0;
            return true;
        }

        $_SESSION['pmd_login_fails'] = $fails + 1;
        if ($fails + 1 >= 5) {
            $_SESSION['pmd_login_lock'] = $now + 60;
            $_SESSION['pmd_login_fails'] = 0;
        }
        return false;
    }

    public static function logout(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    public static function changePin(string $oldPin, string $newPin): array
    {
        $hash = (string)Settings::get('pin_hash', '');
        if ($hash !== '' && !password_verify($oldPin, $hash)) {
            return [false, '原 PIN 不正确'];
        }
        if (strlen($newPin) < 4 || strlen($newPin) > 32) {
            return [false, '新 PIN 长度需为 4-32 位'];
        }
        Settings::set('pin_hash', password_hash($newPin, PASSWORD_DEFAULT));
        return [true, ''];
    }

    public static function hasPin(): bool
    {
        return (string)Settings::get('pin_hash', '') !== '';
    }
}
