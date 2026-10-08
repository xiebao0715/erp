<?php
/** 登录页（独立布局） */
$flash = flash();
$siteName = setting('site_name', '工厂仓库 ERP 管理系统');
$companyName = trim(setting('company_name', ''));
$loginBg = trim(setting('login_bg', ''));
$bgStyle = '';
if ($loginBg !== '' && setting('login_bg_enabled', '0') === '1') {
    $bgFile = dirname(__DIR__, 2) . '/public/' . $loginBg;
    if (is_file($bgFile)) {
        $bgStyle = 'background:linear-gradient(rgba(15,25,40,.45),rgba(15,25,40,.45)),url(\'' . asset($loginBg) . '\') center/cover no-repeat;';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 - <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
</head>
<body class="login-body"<?php if ($bgStyle !== ''): ?> style="<?= e($bgStyle) ?>"<?php endif; ?>>
<div class="login-box">
  <h1><?= e($siteName) ?></h1>
  <p class="sub">请登录后使用</p>

  <?php if ($flash): ?>
    <div class="err-box"><?= e($flash['message']) ?></div>
  <?php endif; ?>

  <form method="post" action="<?= url('auth/login') ?>">
    <input type="hidden" name="<?= \Core\Csrf::KEY ?>" value="<?= e($csrf) ?>">
    <div class="field">
      <label>用户名</label>
      <input type="text" name="username" autofocus required placeholder="请输入用户名" autocomplete="username">
    </div>
    <div class="field">
      <label>密码</label>
      <input type="password" name="password" required placeholder="请输入密码" autocomplete="current-password">
    </div>
    <button type="submit" class="btn-login">登 录</button>
  </form>

  <?php if ($companyName !== ''): ?>
    <p class="company"><?= e($companyName) ?></p>
  <?php endif; ?>
</div>
</body>
</html>
