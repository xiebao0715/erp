<?php
/** 通用打印视图：自包含 HTML，无布局，打印表格清晰 */
use Core\Database;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>打印 - <?= e($title) ?></title>
<style>
  * { box-sizing:border-box; margin:0; padding:0; }
  body { font-family:"Microsoft YaHei",sans-serif; color:#222; padding:24px; font-size:13px; }
  .print-header { text-align:center; margin-bottom:18px; }
  .print-header h1 { font-size:18px; }
  .print-header p { color:#666; font-size:12px; margin-top:4px; }
  .print-meta { display:flex; justify-content:space-between; font-size:12px; color:#666; margin-bottom:12px; border-bottom:1px dashed #ccc; padding-bottom:8px; }
  table { width:100%; border-collapse:collapse; margin-top:6px; }
  th, td { border:1px solid #999; padding:7px 9px; text-align:left; font-size:12px; }
  th { background:#eee; }
  .no-print { text-align:right; margin-bottom:14px; }
  button { padding:6px 16px; cursor:pointer; border:1px solid #999; background:#fff; border-radius:4px; }
  button:hover { background:#f0f0f0; }
  @media print { .no-print { display:none; } th { background:#eee !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; } }
</style>
</head>
<body>
  <div class="no-print"><button onclick="window.print()">🖨 打印</button></div>
  <div class="print-header">
    <h1><?= e(setting('print_title', $siteName ?? '工厂仓库 ERP 管理系统')) ?></h1>
    <p><?= e($title) ?></p>
  </div>
  <div class="print-meta">
    <span>打印时间：<?= e(date('Y-m-d H:i')) ?></span>
    <span>共 <?= count($items) ?> 条记录</span>
  </div>
  <table>
    <thead>
      <tr>
        <?php foreach ($columns as $col): ?><th><?= e($col['label']) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="<?= count($columns) ?>" style="text-align:center;color:#999">无数据</td></tr>
      <?php else: foreach ($items as $row): ?>
        <tr>
          <?php foreach ($columns as $col):
            $val = $row[$col['key']] ?? '';
            $type = $col['type'] ?? 'text';
            if ($type === 'amount' || $type === 'price' || $type === 'number') $val = number_format((float)$val, 2);
            if ($type === 'status') $val = $val == 1 ? '启用' : '停用';
            if ($type === 'yesno') $val = $val == 1 ? '是' : '否';
            if ($type === 'badge_admin') $val = $val == 1 ? '管理员' : '成员';
            if ($type === 'order_status') $val = [0=>'待处理',1=>'已确认',2=>'已完成',3=>'已取消'][(int)$val] ?? '';
          ?>
            <td><?= e($val) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</body>
</html>
