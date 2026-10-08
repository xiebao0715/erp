<?php
/** 生产任务单打印：每张任务单一个区块（任务信息 + 消耗材料明细表） */
$siteName = $siteName ?? '工厂仓库 ERP 管理系统';
$printTitle = setting('print_title', $siteName);
$printer = $printer ?? '';
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
.order-block{margin-bottom:22px;page-break-inside:avoid}
.meta-lines{margin:0 0 8px}
.meta-line{font-size:13px;color:#444;padding:2px 0}
.meta-line span{margin-right:18px;white-space:nowrap}
.meta-line b{color:#555;font-weight:bold}
.prod-line{font-size:14px;font-weight:bold;color:#222;padding:6px 0;border-top:1px dashed #aaa;border-bottom:1px dashed #aaa;margin:6px 0}
.prod-line span{margin-right:24px}
table.items-table{width:100%;border-collapse:collapse;margin:6px 0}
table.items-table th,table.items-table td{border:1px solid #999;padding:6px 8px;font-size:12px;text-align:center}
table.items-table th{background:#eee;-webkit-print-color-adjust:exact;print-color-adjust:exact}
tr.group-total td{font-weight:bold;background:#fcfcfc;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.toolbar{display:flex;justify-content:flex-end;margin-bottom:14px;gap:8px}
@media print{ .toolbar{display:none} .order-block{page-break-after:always} .order-block:last-child{page-break-after:auto} }
</style>
</head>
<body>
<div class="toolbar"><button class="btn btn-primary" onclick="window.print()">🖨 打印</button></div>
<div class="print-header">
  <h2><?= e($printTitle) ?></h2>
  <p><?= e($title) ?> · 打印时间：<?= e(date('Y-m-d H:i:s')) ?> · 打印人：<?= e($printer) ?></p>
</div>

<?php if (empty($orders)): ?>
  <div style="text-align:center;color:#999;padding:30px">暂无数据</div>
<?php else: foreach ($orders as $o):
  $items = $itemsByOrder[$o['id']] ?? [];
?>
<div class="order-block">
  <div class="meta-lines">
    <div class="meta-line">
      <span><b>任务单号：</b><?= e($o['order_no']) ?></span>
      <span><b>生产日期：</b><?= e($o['production_date']) ?></span>
      <span><b>状态：</b><?= (int)$o['status']===1 ? '已完成' : ((int)$o['status']===2 ? '生产中（已领料，成品未入库）' : ((int)$o['status']===3 ? '已取消（数量已全部取消）' : '待生产')) ?></span>
      <span><b>领料仓库：</b><?= e($o['warehouse_name'] ?: '-') ?></span>
      <span><b>入库仓库：</b><?= e($o['inbound_warehouse_name'] ?? '-') ?: '-' ?></span>
    </div>
    <div class="meta-line">
      <span><b>目标产线：</b><?= e($o['line_name'] ?: '-') ?></span>
      <span><b>产线负责人：</b><?= e(($o['line_manager'] ?? '') !== '' ? $o['line_manager'] : '-') ?></span>
      <span><b>经办人：</b><?= e($o['operator']) ?></span>
      <span><b>领料出库单：</b><?= e($o['outbound_no'] ?: '-') ?></span>
      <span><b>成品入库单：</b><?= e($o['inbound_no'] ?? '-') ?: '-' ?></span>
      <?php if (!empty($o['remark'])): ?><span><b>备注：</b><?= e($o['remark']) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="prod-line">
    <span>生产商品：<?= e($o['product_name']) ?></span>
    <span>规格：<?= e($o['product_spec'] ?: '-') ?></span>
    <span>生产数量：<?= number_format((float)$o['quantity'], 2) ?> <?= e($o['product_unit']) ?></span>
    <?php
      $cancelPending = (float)($o['cancel_pending_qty'] ?? 0);
      $cancelDone = (float)($o['cancel_done_qty'] ?? 0);
      if ($cancelPending > 0 || $cancelDone > 0):
        // quantity 已是扣除已确认取消后的净额，预计完工仅需再减去待确认取消量
    ?>
    <span style="color:#e67e22">待取消：<?= number_format($cancelPending, 2) ?></span>
    <span style="color:#888">已取消：<?= number_format($cancelDone, 2) ?></span>
    <span>预计完工入库：<?= number_format(max(0, (float)$o['quantity'] - $cancelPending), 2) ?> <?= e($o['product_unit']) ?></span>
    <?php endif; ?>
  </div>
  <table class="items-table">
    <thead><tr>
      <th style="width:40px">#</th><th>材料编码</th><th>材料名称</th><th>规格</th>
      <th style="width:60px">单位</th><th style="width:80px">消耗数量</th><th style="width:90px">单价</th><th style="width:110px">金额</th><th>备注</th>
    </tr></thead>
    <tbody>
    <?php $seq = 0; $grandQty = 0; $grandAmt = 0;
    foreach ($items as $it): $seq++; $grandQty += (float)$it['quantity']; $grandAmt += (float)$it['amount']; ?>
      <tr>
        <td><?= $seq ?></td>
        <td><?= e($it['material_code'] ?? '') ?></td>
        <td><?= e($it['material_name'] ?? '') ?></td>
        <td><?= e($it['material_spec'] ?? '') ?></td>
        <td><?= e($it['material_unit'] ?? '') ?></td>
        <td><?= number_format((float)$it['quantity'], 2) ?></td>
        <td><?= number_format((float)$it['unit_price'], 2) ?></td>
        <td>¥<?= number_format((float)$it['amount'], 2) ?></td>
        <td><?= e($it['remark']) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="group-total">
        <td colspan="5" style="text-align:right"><b>合计</b></td>
        <td><?= number_format($grandQty, 2) ?></td>
        <td></td>
        <td>¥<?= number_format($grandAmt, 2) ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>
</div>
<?php endforeach; endif; ?>
</body>
</html>
