<?php
/**
 * 部署引导：首次访问时配置数据库与管理员账号
 * - 第一步：填写服务器信息（数据库地址/端口/库名/账号/密码）
 * - 第二步：填写管理员账号与密码
 * 部署完成后自动初始化数据库并写入 config/config.php，跳转登录页。
 * 已安装时再次访问会提示并跳转系统首页。
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Shanghai');

define('BASE_PATH', dirname(__FILE__));
define('CONFIG_FILE', BASE_PATH . '/config/config.php');
define('SCHEMA_FILE', BASE_PATH . '/database/schema.sql');

// 已安装则跳走
if (is_file(CONFIG_FILE)) {
    header('Location: index.php');
    exit;
}

$errors  = [];
$success = false;
$step    = $_POST['step'] ?? '1';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim((string)($_POST['db_host'] ?? ''));
    $dbPort = trim((string)($_POST['db_port'] ?? '3306'));
    $dbName = trim((string)($_POST['db_name'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminName = trim((string)($_POST['admin_name'] ?? '管理员'));

    // 校验
    if ($dbHost === '') $errors[] = '请填写数据库地址';
    if ($dbPort === '') $errors[] = '请填写数据库端口';
    if ($dbName === '') $errors[] = '请填写数据库名称';
    if ($dbUser === '') $errors[] = '请填写数据库用户名';
    if (mb_strlen($adminUser) < 3) $errors[] = '管理员用户名至少 3 个字符';
    if (mb_strlen($adminPass) < 6) $errors[] = '管理员密码至少 6 位';

    if (!$errors) {
        try {
            // 连接 MySQL（不指定库名，用于创建库）
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);

            // 创建数据库（如不存在）
            $safeDbName = preg_replace('/[^A-Za-z0-9_]/', '', $dbName);
            if ($safeDbName === '') {
                throw new RuntimeException('数据库名称包含非法字符');
            }
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            $pdo->exec("USE `{$safeDbName}`");

            // 执行建表 SQL
            $sql = file_get_contents(SCHEMA_FILE);
            if ($sql === false) {
                throw new RuntimeException('无法读取 database/schema.sql，请确认文件存在');
            }
            // 拆分语句执行（兼容多条语句）
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt !== '' && stripos($stmt, 'SET ') !== 0) {
                    $pdo->exec($stmt);
                }
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

            // 创建管理员
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (username, password, name, is_admin, permissions, phone, status) 
                 VALUES (:u, :p, :n, 1, NULL, '', 1)"
            );
            $stmt->execute([
                'u' => $adminUser,
                'p' => $hash,
                'n' => $adminName !== '' ? $adminName : '管理员',
            ]);

            // 写配置文件
            $installKey = bin2hex(random_bytes(16));
            $configContent = "<?php\n"
                . "// 由 install.php 自动生成，请勿手动修改\n"
                . "return [\n"
                . "    'db' => [\n"
                . "        'host'    => " . var_export($dbHost, true) . ",\n"
                . "        'port'    => " . var_export($dbPort, true) . ",\n"
                . "        'name'    => " . var_export($safeDbName, true) . ",\n"
                . "        'user'    => " . var_export($dbUser, true) . ",\n"
                . "        'pass'    => " . var_export($dbPass, true) . ",\n"
                . "        'charset' => 'utf8mb4',\n"
                . "    ],\n"
                . "    'installed'   => true,\n"
                . "    'install_key' => " . var_export($installKey, true) . ",\n"
                . "];\n";

            $configDir = dirname(CONFIG_FILE);
            if (!is_dir($configDir)) {
                mkdir($configDir, 0755, true);
            }
            if (file_put_contents(CONFIG_FILE, $configContent) === false) {
                throw new RuntimeException('无法写入 config/config.php，请检查目录权限');
            }

            // 创建必要目录
            $logDir = BASE_PATH . '/storage/logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $success = true;
        } catch (Throwable $e) {
            $errors[] = '部署失败：' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>部署引导 - 工厂仓库 ERP 管理系统</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: "Microsoft YaHei","Segoe UI",sans-serif; background:#f0f2f5; color:#333; padding:30px 15px; }
  .wrap { max-width:720px; margin:0 auto; background:#fff; border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.08); overflow:hidden; }
  .head { background:linear-gradient(135deg,#1e6fff,#2d8bff); color:#fff; padding:28px 32px; }
  .head h1 { font-size:22px; margin-bottom:6px; }
  .head p { opacity:.9; font-size:13px; }
  .body { padding:28px 32px; }
  .step-bar { display:flex; margin-bottom:24px; }
  .step { flex:1; text-align:center; position:relative; padding-bottom:14px; font-size:13px; color:#999; }
  .step.active { color:#1e6fff; font-weight:bold; }
  .step .dot { width:28px; height:28px; line-height:28px; border-radius:50%; background:#e0e0e0; color:#fff; margin:0 auto 6px; font-weight:bold; }
  .step.active .dot { background:#1e6fff; }
  .step::after { content:''; position:absolute; bottom:4px; left:50%; width:100%; height:2px; background:#e0e0e0; }
  .step:last-child::after { display:none; }
  .step.active::after { background:#1e6fff; }
  .field { margin-bottom:18px; }
  .field label { display:block; margin-bottom:6px; font-size:14px; font-weight:600; color:#444; }
  .field label .req { color:#e74c3c; }
  .field input { width:100%; padding:10px 12px; border:1px solid #d9d9d9; border-radius:6px; font-size:14px; transition:border-color .2s; }
  .field input:focus { outline:none; border-color:#1e6fff; box-shadow:0 0 0 3px rgba(30,111,255,.12); }
  .hint { font-size:12px; color:#999; margin-top:4px; }
  .group-title { font-size:15px; font-weight:bold; color:#1e6fff; margin:0 0 16px; padding-bottom:8px; border-bottom:1px solid #eef2f7; }
  .btn { width:100%; padding:12px; background:#1e6fff; color:#fff; border:none; border-radius:6px; font-size:15px; font-weight:bold; cursor:pointer; transition:background .2s; }
  .btn:hover { background:#1957d6; }
  .btn:disabled { background:#bbb; cursor:not-allowed; }
  .errors { background:#fdeaea; border:1px solid #f5c6cb; color:#c0392b; padding:12px 16px; border-radius:6px; margin-bottom:18px; font-size:13px; }
  .errors li { margin:4px 0; list-style:none; }
  .success-box { text-align:center; padding:20px 0; }
  .success-box .ico { font-size:54px; color:#27ae60; }
  .success-box h2 { color:#27ae60; margin:10px 0; }
  .success-box p { color:#666; margin-bottom:20px; font-size:14px; }
  .success-box a { display:inline-block; padding:12px 32px; background:#1e6fff; color:#fff; text-decoration:none; border-radius:6px; font-weight:bold; }
  .warn { background:#fff8e1; border:1px solid #ffe082; color:#9a6a00; padding:10px 14px; border-radius:6px; font-size:12px; margin-top:18px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="head">
    <h1>工厂仓库 ERP 管理系统</h1>
    <p>部署引导 · 完成后即可使用系统</p>
  </div>
  <div class="body">
    <?php if ($success): ?>
      <div class="success-box">
        <div class="ico">&#10004;</div>
        <h2>部署成功！</h2>
        <p>数据库已初始化，管理员账号已创建。<br>建议删除 install.php 以提高安全性。</p>
        <a href="index.php">进入登录页面</a>
      </div>
    <?php else: ?>
      <div class="step-bar">
        <div class="step active"><div class="dot">1</div>服务器信息</div>
        <div class="step active"><div class="dot">2</div>管理员账号</div>
        <div class="step"><div class="dot">3</div>完成</div>
      </div>

      <?php if ($errors): ?>
        <div class="errors">
          <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="step" value="2">

        <div class="group-title">数据库服务器信息</div>
        <div class="field">
          <label>数据库地址 <span class="req">*</span></label>
          <input type="text" name="db_host" value="<?= h($_POST['db_host'] ?? '127.0.0.1') ?>" placeholder="127.0.0.1">
        </div>
        <div class="field">
          <label>数据库端口 <span class="req">*</span></label>
          <input type="text" name="db_port" value="<?= h($_POST['db_port'] ?? '3306') ?>" placeholder="3306">
        </div>
        <div class="field">
          <label>数据库名称 <span class="req">*</span></label>
          <input type="text" name="db_name" value="<?= h($_POST['db_name'] ?? '') ?>" placeholder="erp_warehouse">
          <div class="hint">数据库不存在时将自动创建</div>
        </div>
        <div class="field">
          <label>数据库用户名 <span class="req">*</span></label>
          <input type="text" name="db_user" value="<?= h($_POST['db_user'] ?? '') ?>" placeholder="root">
        </div>
        <div class="field">
          <label>数据库密码</label>
          <input type="password" name="db_pass" value="<?= h($_POST['db_pass'] ?? '') ?>" placeholder="留空表示无密码">
        </div>

        <div class="group-title" style="margin-top:24px;">管理员账号</div>
        <div class="field">
          <label>管理员用户名 <span class="req">*</span></label>
          <input type="text" name="admin_user" value="<?= h($_POST['admin_user'] ?? 'admin') ?>" placeholder="admin">
          <div class="hint">管理员拥有系统最高权限且不可删除</div>
        </div>
        <div class="field">
          <label>管理员密码 <span class="req">*</span></label>
          <input type="password" name="admin_pass" value="" placeholder="至少 6 位">
        </div>
        <div class="field">
          <label>管理员姓名</label>
          <input type="text" name="admin_name" value="<?= h($_POST['admin_name'] ?? '管理员') ?>" placeholder="管理员">
        </div>

        <button type="submit" class="btn">开始部署</button>
      </form>
      <div class="warn">部署完成后配置文件将写入 config/config.php，敏感信息请妥善保管。为安全起见，部署成功后请删除 install.php 文件。</div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
