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

if (!function_exists('icon_map')) {
    /**
     * 内联 SVG 图标库（Lucide 风格描边图标，24x24，currentColor 着色）
     * 用图标替代页面中的 emoji 表情，颜色随上下文 color 自动适配
     */
    function icon_map(): array
    {
        static $map = [
            // 导航 / 模块
            'home'            => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
            'users'           => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'warehouse'       => '<path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/><rect width="12" height="12" x="6" y="10"/>',
            'package'         => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="m7.5 4.27 9 5.15"/>',
            'folder'          => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
            'ruler'           => '<path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.41 2.41 0 0 1 0-3.4l2.6-2.6a2.41 2.41 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2"/><path d="m11.5 9.5 2-2"/><path d="m8.5 6.5 2-2"/><path d="m17.5 15.5 2-2"/>',
            'arrow-down-line' => '<path d="M12 17V3"/><path d="m6 11 6 6 6-6"/><path d="M19 21H5"/>',
            'arrow-up-line'   => '<path d="m18 9-6-6-6 6"/><path d="M12 3v14"/><path d="M5 21h14"/>',
            'factory'         => '<path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M17 18h1"/><path d="M12 18h1"/><path d="M7 18h1"/>',
            'wrench'          => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'chart-column'    => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
            'truck'           => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
            'handshake'       => '<path d="m11 17 2 2a1 1 0 1 0 3-3"/><path d="m14 14 2.5 2.5a1 1 0 1 0 3-3l-3.88-3.88a3 3 0 0 0-4.24 0l-.88.88a1 1 0 1 1-3-3l2.81-2.81a5.79 5.79 0 0 1 7.06-.87l.47.28a2 2 0 0 0 1.42.25L21 4"/><path d="m21 3 1 11h-2"/><path d="M3 3 2 14l6.5 6.5a1 1 0 1 0 3-3"/><path d="M3 4h8"/>',
            'trending-up'     => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
            'settings'        => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
            // 操作 / 状态
            'plus'            => '<path d="M5 12h14"/><path d="M12 5v14"/>',
            'x'               => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'check'           => '<polyline points="20 6 9 17 4 12"/>',
            'circle-check'    => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'alert-triangle'  => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/>',
            'printer'         => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/>',
            'image'           => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
            'save'            => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/>',
            'lock'            => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'clipboard-list'  => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/><path d="M8 11h.01"/><path d="M8 16h.01"/>',
            'file-text'       => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        ];
        return $map;
    }
}

if (!function_exists('icon')) {
    /**
     * 输出内联 SVG 图标
     * 用法：icon('plus')、icon('x', ['style' => 'width:18px'])
     * 尺寸默认 1em（随 font-size 缩放），颜色继承 currentColor
     */
    function icon(string $name, array $attrs = []): string
    {
        $body = icon_map()[$name] ?? '';
        $class = 'icon icon-' . $name;
        if (isset($attrs['class'])) {
            $class .= ' ' . $attrs['class'];
        }
        $extra = '';
        foreach ($attrs as $k => $v) {
            if ($k === 'class') {
                continue;
            }
            $extra .= ' ' . $k . '="' . e((string)$v) . '"';
        }
        return '<svg class="' . $class . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
             . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"'
             . $extra . '>' . $body . '</svg>';
    }
}
