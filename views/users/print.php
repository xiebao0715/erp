<?php /** 成员列表打印（自包含） */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>打印 - 成员管理</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:"Microsoft YaHei",sans-serif; color:#222; padding:24px; font-size:13px; }
  .print-header { text-align:center; margin-bottom:18px; }
  .print-header h1 { font-size:18px; }
  .print-header p { color:#666; font-size:12px; margin-top:4px; }
  .print-meta { display:flex; justify-content:space-between; font-size:12px; color:#666; margin-bottom:12px; border-bottom:1px dashed #ccc; padding-bottom:8px; }
  table { width:100%; border-collapse:collapse; }
  th, td { border:1px solid #999; padding:7px 9px; text-align:left; font-size:12px; }
  th { background:#eee; }
  .no-print { text-align:right; margin-bottom:14px; }
  button { padding:6px 16px; cursor:pointer; border:1px solid #999; background:#fff; border-radius:4px; }
  @media print { .no-print { display:none; } th { background:#eee !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>
</head>
<body>
  <div class="no-print"><button onclick="window.print()">🖨 打印</button></div>
  <div class="print-header"><h1><?= e(setting('print_title', '工厂仓库 ERP 管理系统')) ?></h1><p>成员管理</p></div>
  <div class="print-meta"><span>打印时间：<?= e(date('Y-m-d H:i')) ?></span><span>共 <?= count($items) ?> 条</span></div>
  <table>
    <thead><tr><th>ID</th><th>用户名</th><th>姓名</th><th>角色</th><th>电话</th><th>状态</th><th>最近登录</th><th>创建时间</th></tr></thead>
    <tbody>
      <?php foreach ($items as $r): ?>
      <tr><td><?= e($r['id']) ?></td><td><?= e($r['username']) ?></td><td><?= e($r['name']) ?></td><td><?= (int)$r['is_admin']===1?'管理员':'成员' ?></td><td><?= e($r['phone']) ?></td><td><?= (int)$r['status']===1?'启用':'禁用' ?></td><td><?= e($r['last_login']?:'-') ?></td><td><?= e($r['created_at']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>
