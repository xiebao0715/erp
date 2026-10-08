<?php
/**
 * CSRF 防护：令牌生成与校验
 */
declare(strict_types=1);

namespace Core;

final class Csrf
{
    public const KEY = '_csrf_token';

    public static function token(): string
    {
        if (!isset($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    /** 校验请求中的 CSRF 令牌，失败抛异常 */
    public static function verify(?string $token = null): void
    {
        $token ??= $_POST[self::KEY] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $session = $_SESSION[self::KEY] ?? null;
        if (!$token || !$session || !hash_equals($session, $token)) {
            throw new \RuntimeException('CSRF 令牌校验失败，请刷新页面重试');
        }
    }

    /** 输出隐藏域 HTML */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::KEY . '" value="' . e(self::token()) . '">';
    }
}
