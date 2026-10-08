<?php
/**
 * 全局辅助函数
 */
declare(strict_types=1);

if (!function_exists('is_ajax')) {
    function is_ajax(): bool
    {
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        return strtolower($requestedWith) === 'xmlhttprequest';
    }
}

if (!function_exists('e')) {
    /** 输出转义，防止 XSS */
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('url')) {
    /** 生成站点内 URL，默认走 ?r= 路由 */
    function url(string $path = '', array $params = []): string
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $query = $path !== '' ? '?r=' . $path : '';
        if ($params) {
            $extra = http_build_query($params);
            $query .= ($query !== '' ? '&' : '?') . $extra;
        }
        return $base . '/index.php' . $query;
    }
}

if (!function_exists('asset')) {
    /** 静态资源 URL */
    function asset(string $path): string
    {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $rel = ltrim($path, '/');
        $url = $base . '/public/' . $rel;
        // 以文件修改时间作为版本号，文件更新后 URL 自动变化，避免浏览器缓存旧版 JS/CSS
        $file = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/public/' . $rel;
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
        return $url;
    }
}

if (!function_exists('json_response')) {
    function json_response(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('input')) {
    /** 取请求参数（POST 优先），支持默认值 */
    function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return $_POST[$key];
        }
        if (array_key_exists($key, $_GET)) {
            return $_GET[$key];
        }
        return $default;
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?Core\Auth
    {
        return Core\Auth::instance();
    }
}

if (!function_exists('can')) {
    function can(string $module, string $action = 'view'): bool
    {
        return Core\Permission::check($module, $action);
    }
}

if (!function_exists('old')) {
    /** 表单回填值 */
    function old(string $key, mixed $default = ''): mixed
    {
        return $_SESSION['_old_input'][$key] ?? $default;
    }
}

if (!function_exists('flash')) {
    /** 取闪存消息后清除 */
    function flash(): ?array
    {
        $flash = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return $flash;
    }
}

if (!function_exists('gen_order_no')) {
    /** 生成单据编号 */
    function gen_order_no(string $prefix): string
    {
        return $prefix . date('YmdHis') . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('setting')) {
    /** 读取系统设置（按 key 静态缓存）；值为空时返回默认值 */
    function setting(string $key, string $default = ''): string
    {
        static $cache = [];
        if (!array_key_exists($key, $cache)) {
            $value = '';
            try {
                $row = Core\Database::instance()->fetch("SELECT value FROM settings WHERE `key` = :k", ['k' => $key]);
                $value = (string)($row['value'] ?? '');
            } catch (\Throwable) {
                $value = '';
            }
            $cache[$key] = $value;
        }
        return $cache[$key] !== '' ? $cache[$key] : $default;
    }
}
