<?php
/**
 * 认证与会话管理
 */
declare(strict_types=1);

namespace Core;

final class Auth
{
    private static ?Auth $instance = null;
    private ?array $user = null;

    private function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            ]);
            session_name('ERPSESSID');
            session_start();
        }

        $uid = $_SESSION['uid'] ?? null;
        if ($uid !== null) {
            $db = Database::instance();
            $this->user = $db->fetch('SELECT * FROM users WHERE id = :id AND status = 1', ['id' => $uid]);
            if ($this->user === null) {
                $this->logout();
            }
        }
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /** 尝试登录，返回是否成功 */
    public function attempt(string $username, string $password): bool
    {
        $db = Database::instance();
        $user = $db->fetch('SELECT * FROM users WHERE username = :u LIMIT 1', ['u' => $username]);

        if (!$user) {
            return false;
        }
        if ((int)$user['status'] !== 1) {
            return false;
        }
        if (!password_verify($password, $user['password'])) {
            return false;
        }

        // 升级到更强哈希
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            $db->update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $user['id']]);
        }

        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$user['id'];
        $this->user = $user;
        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? true);
        }
        session_destroy();
        $this->user = null;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function user(): ?array
    {
        return $this->user;
    }

    public function id(): int
    {
        return (int)($this->user['id'] ?? 0);
    }

    public function name(): string
    {
        return (string)($this->user['name'] ?? $this->user['username'] ?? '');
    }

    public function username(): string
    {
        return (string)($this->user['username'] ?? '');
    }

    public function isAdmin(): bool
    {
        return isset($this->user['is_admin']) && (int)$this->user['is_admin'] === 1;
    }
}
