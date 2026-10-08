<?php
/** 库存清单/预警清单打印 */
$siteName = $siteName ?? '工厂仓库 ERP 管理系统';
$printTitle = setting('print_title', $siteName); // 打印抬头：优先取系统设置里的「打印抬头」
$isWarning = $isWarning ?? false;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title><?= e($title) ?> - <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<style>
body{background:#fff;padding:20px}
.print-header{display:block!important;text-align:center;margin-bottom:16px}
.print-header h2{font-size:20px}
.print-header p{font-size:12px;color:#666;margin-top:4px}
table.data-table th,table.data-table td{font-size:12px;padding:6px 8px}
table.data-table th{background:#eee;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.badge-low{color:#e74c3c;font-weight:bold}
.toolbar{display:flex;justify-content:flex-end;margin-bottom:14px;gap:8px}
@media print{ .toolbar{display:none} }
</style>
</head>
<body>
<div class="toolbar"><button class="btn btn-primary" onclick="window.print()">🖨 打印</button></div>
<div class="print-header">
  <h2><?= e($printTitle) ?></h2>
  <p><?= e($title) ?> · 打印时间：<?= e(date('Y-m-d H:i:s')) ?></p>
</div>

<?php if (empty($items)): ?>
  <div style="text-align:center;color:#999;padding:30px">暂无数据</div>
<?php else: ?>
<div class="table-wrap">
<table class="data-table">
  <thead><tr>
    <th>仓库编码</th><th>仓库名称</th><th>商品编码</th><th>商品名称</th>
    <th>规格</th><th>单位</th><th>当前库存</th><th>最低库存</th><th>最高库存</th><th>状态</th>
  </tr></thead>
  <tbody>
  <?php foreach ($items as $r):
    $qty = (float)$r['quantity']; $min = (float)($r['min_stock'] ?? 0); $max = (float)($r['max_stock'] ?? 0);
    $isLow = $qty < $min;
  ?>
    <tr>
      <td><?= e($r['warehouse_code'] ?? '') ?: '-' ?></td>
      <td><?= e($r['warehouse_name'] ?? '') ?: '未入库' ?></td>
      <td><?= e($r['product_code']) ?></td>
      <td><?= e($r['product_name']) ?></td>
      <td><?= e($r['product_spec']) ?></td>
      <td><?= e($r['product_unit']) ?></td>
      <td class="<?= $isLow?'badge-low':'' ?>"><?= number_format($qty, 2) ?></td>
      <td><?= number_format($min, 2) ?></td>
      <td><?= $max>0 ? number_format($max, 2) : '-' ?></td>
      <td><?= $isLow ? '<span class="badge-low">不足</span>' : '正常' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
</body>
</html>
