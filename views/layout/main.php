<?php
/** @var Core\Auth $current_user */
/** @var array $visible_modules */
/** @var string $__content */
use Core\Auth;
use Core\Csrf;
use Core\Permission;
$cu = $current_user ?? Auth::instance();
$curRoute = trim((string)($_GET['r'] ?? ''), '/');
$curModule = explode('/', $curRoute)[0] ?? '';
$csrf = Csrf::token();
$siteName = '工厂仓库 ERP 管理系统';
try {
    $db = Core\Database::instance();
    $row = $db->fetch("SELECT value FROM settings WHERE `key`='site_name'");
    if ($row) $siteName = $row['value'];
} catch (Throwable $e) {}

// 侧边栏菜单图标
$icons = [
    'dashboard' => '🏠', 'user' => '👥', 'warehouse' => '🏭', 'product' => '📦', 'basic' => '🗂',
    'inbound' => '📥', 'outbound' => '📤', 'production' => '🏗️', 'line' => '🛠️', 'inventory' => '📊', 'supplier' => '🚚',
    'customer' => '🤝', 'report' => '📈', 'settings' => '⚙️',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e($csrf) ?>">
<title><?= e($title ?? $siteName) ?> - <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<script src="<?= asset('js/app.js') ?>"></script>
</head>
<body>
<div class="topbar">
  <div class="brand">
    <span class="logo">ERP</span>
    <span><?= e($siteName) ?></span>
  </div>
  <div class="topbar-right">
    <div class="user-info">
      <span class="avatar"><?= e(mb_substr($cu->name(), 0, 1)) ?></span>
      <span><?= e($cu->name()) ?><?php if ($cu->isAdmin()): ?> <span style="color:#5aa0ff;font-size:11px">管理员</span><?php endif; ?></span>
    </div>
    <button class="btn-logout" onclick="if(confirm('确定要退出登录吗？')) location.href='<?= url('auth/logout') ?>'">退出</button>
  </div>
</div>

<div class="layout">
  <div class="sidebar">
    <div class="menu-group-title">功能菜单</div>
    <?php foreach ($visible_modules as $key => $label):
        $url = url($key . '/index');
        $active = ($curModule === $key) ? 'active' : '';
    ?>
      <a class="menu-item <?= $active ?>" href="<?= $url ?>"><span class="ico"><?= $icons[$key] ?? '•' ?></span><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </div>

  <div class="main">
    <div class="content-card">
      <?= $__content ?>
    </div>
  </div>
</div>

<div id="toastBox" class="toast"></div>
<script>
  // 显示服务端闪存消息
  <?php if ($flash = flash()): ?>
    window.addEventListener('load', function(){ toast(<?= json_encode($flash['message'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($flash['type'], JSON_UNESCAPED_UNICODE) ?>); });
  <?php endif; ?>
</script>
<?php if (!empty($scripts)) echo $scripts; ?>
</body>
</html>
