<?php
$siteName = $siteName ?? '工厂仓库 ERP 管理系统';
$printTitle = setting('print_title', $siteName); // 打印抬头：优先取系统设置里的「打印抬头」
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
.summary-row{display:flex;flex-wrap:wrap;gap:14px;margin:14px 0}
.summary-row .item{border:1px solid #ddd;border-radius:4px;padding:10px 14px;flex:1;min-width:140px}
.summary-row .item .lbl{color:#888;font-size:12px;margin-bottom:4px}
.summary-row .item .val{font-size:16px;font-weight:bold}
.section-title{font-size:15px;font-weight:bold;margin:20px 0 8px}
.toolbar{display:flex;justify-content:flex-end;margin-bottom:14px;gap:8px}
@media print{ .toolbar{display:none} }
</style>
</head>
<body>
<div class="toolbar"><button class="btn btn-primary" onclick="window.print()">🖨 打印</button></div>
<div class="print-header">
  <h2><?= e($printTitle) ?></h2>
  <p><?= e($title) ?> · 日期范围：<?= e($from) ?> ~ <?= e($to) ?> · 打印时间：<?= e(date('Y-m-d H:i:s')) ?></p>
</div>

<div class="summary-row">
  <div class="item"><div class="lbl">入库单数</div><div class="val"><?= (int)($inboundStats['cnt'] ?? 0) ?></div></div>
  <div class="item"><div class="lbl">入库金额</div><div class="val">¥<?= number_format((float)($inboundStats['amount'] ?? 0), 2) ?></div></div>
  <div class="item"><div class="lbl">出库单数</div><div class="val"><?= (int)($outboundStats['cnt'] ?? 0) ?></div></div>
  <div class="item"><div class="lbl">出库金额</div><div class="val">¥<?= number_format((float)($outboundStats['amount'] ?? 0), 2) ?></div></div>
  <div class="item"><div class="lbl">当前库存项</div><div class="val"><?= (int)($inventoryStats['cnt'] ?? 0) ?></div></div>
  <div class="item"><div class="lbl">库存总量</div><div class="val"><?= number_format((float)($inventoryStats['qty'] ?? 0), 2) ?></div></div>
</div>

<div class="section-title">入库明细</div>
<div class="table-wrap">
<table class="data-table">
  <thead><tr><th>单号</th><th>入库日期</th><th>仓库</th><th>供应商</th><th>经办人</th><th>金额</th></tr></thead>
  <tbody>
  <?php if (empty($inboundItems)): ?>
    <tr class="empty-row"><td colspan="6">暂无数据</td></tr>
  <?php else: foreach ($inboundItems as $r): ?>
    <tr>
      <td><?= e($r['order_no']) ?></td>
      <td><?= e($r['inbound_date']) ?></td>
      <td><?= e($r['warehouse_name']) ?></td>
      <td><?= e($r['supplier_name']) ?></td>
      <td><?= e($r['operator']) ?></td>
      <td>¥<?= number_format((float)$r['total_amount'], 2) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<div class="section-title">出库明细</div>
<div class="table-wrap">
<table class="data-table">
  <thead><tr><th>单号</th><th>出库日期</th><th>仓库</th><th>客户</th><th>经办人</th><th>金额</th></tr></thead>
  <tbody>
  <?php if (empty($outboundItems)): ?>
    <tr class="empty-row"><td colspan="6">暂无数据</td></tr>
  <?php else: foreach ($outboundItems as $r): ?>
    <tr>
      <td><?= e($r['order_no']) ?></td>
      <td><?= e($r['outbound_date']) ?></td>
      <td><?= e($r['warehouse_name']) ?></td>
      <td><?= e($r['customer_name']) ?></td>
      <td><?= e($r['operator']) ?></td>
      <td>¥<?= number_format((float)$r['total_amount'], 2) ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>
</body>
</html>
