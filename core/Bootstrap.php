<?php
/**
 * 路由分发与启动
 * 路由格式：index.php?r=Controller/method（method 缺省为 index）
 * 伪静态路由 warehouse/index 也会被 .htaccess 改写为 ?r=
 */
declare(strict_types=1);

namespace Core;

final class Bootstrap
{
    public static function run(array $config): void
    {
        $GLOBALS['_db_config'] = $config['db'] ?? [];

        $route = trim((string)($_GET['r'] ?? ''), '/');
        $parts = $route !== '' ? explode('/', $route) : [];

        $controllerKey = $parts[0] ?? '';
        $method       = $parts[1] ?? 'index';

        // 白名单控制器（无需登录）
        $public = ['auth'];

        if ($controllerKey === '') {
            $auth = Auth::instance();
            if ($auth->check()) {
                redirect(url('dashboard/index'));
            } else {
                redirect(url('auth/login'));
            }
        }

        $controllerKey = strtolower($controllerKey);
        $controllerClass = 'App\\Controllers\\' . ucfirst($controllerKey) . 'Controller';

        if (!class_exists($controllerClass)) {
            http_response_code(404);
            echo '页面不存在';
            exit;
        }

        // 登录校验
        if (!in_array($controllerKey, $public, true)) {
            $auth = Auth::instance();
            if (!$auth->check()) {
                if (is_ajax()) {
                    json_response(['success' => false, 'message' => '登录已过期，请重新登录', 'code' => 401], 401);
                }
                redirect(url('auth/login'));
            }
        }

        $controller = new $controllerClass();

        // 方法存在性
        if (!method_exists($controller, $method) || !is_callable([$controller, $method])) {
            http_response_code(404);
            echo '方法不存在';
            exit;
        }

        // 反射保护：只允许 public 方法作为路由入口
        $ref = new \ReflectionMethod($controller, $method);
        if (!$ref->isPublic() || str_starts_with($method, '_')) {
            http_response_code(404);
            echo '方法不存在';
            exit;
        }

        $controller->_before($method);
        $controller->{$method}();
    }
}
