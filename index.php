<?php
/**
 * 工厂仓库 ERP 管理系统 - 前端控制器入口
 * 支持任意 PHP 8.2 环境，无需 mod_rewrite 也能通过 ?r= 路由运行。
 *
 * 首次访问若未完成安装，自动跳转到部署引导 install.php。
 */

declare(strict_types=1);

// PHP 运行环境基础设定
error_reporting(E_ALL);
ini_set('display_errors', '0'); // 生产关闭错误输出，避免暴露路径
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Shanghai');

// 系统根目录
define('BASE_PATH', dirname(__FILE__));
define('CORE_PATH', BASE_PATH . '/core');
define('APP_PATH', BASE_PATH . '/app');
define('VIEW_PATH', BASE_PATH . '/views');
define('PUBLIC_PATH', BASE_PATH . '/public');
define('CONFIG_FILE', BASE_PATH . '/config/config.php');

// 注册自动加载
spl_autoload_register(function (string $class): void {
    // 支持命名空间 App\ 与 Core\
    $prefixes = [
        'Core\\' => CORE_PATH . '/',
        'App\\'  => APP_PATH . '/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $relative = str_replace('\\', '/', $relative);
            $file = $base . $relative . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

// 辅助函数
require_once CORE_PATH . '/helpers.php';

// ============ 安装检测 ============
// 未检测到配置文件 → 进入部署引导
if (!is_file(CONFIG_FILE)) {
    header('Location: install.php');
    exit;
}

// 加载配置
$config = require CONFIG_FILE;

// 自定义错误处理：将错误转为异常，保证未捕获异常统一反馈
set_error_handler(function (int $errno, string $errstr, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    throw new ErrorException($errstr, 0, $errno, $file, $line);
});

set_exception_handler(function (Throwable $e) use ($config): void {
    $log = sprintf(
        "[%s] %s in %s:%d\n%s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    @file_put_contents(BASE_PATH . '/storage/logs/error.log', $log, FILE_APPEND | LOCK_EX);

    if (str_starts_with($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') || is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '服务器内部错误，请重试'], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo '<!DOCTYPE html><meta charset="utf-8"><h3>服务器内部错误</h3><p>请联系管理员查看日志。</p>';
    }
    exit;
});

// ============ 路由分发 ============
try {
    // 数据库结构自动升级（幂等，已装系统自动补表补列迁移旧数据）
    Core\Upgrade::run($config);
    Core\Bootstrap::run($config);
} catch (Throwable $e) {
    $handler = set_exception_handler(null);
    $handler($e);
}
