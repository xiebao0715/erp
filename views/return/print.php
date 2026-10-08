<?php
/** 退回单打印视图，可输出多张：商品统一一张大表，单据信息显示在表格上方（不显示单号，经办人为打印人） */
$siteName = $siteName ?? '工厂仓库 ERP 管理系统';
$printTitle = setting('print_title', $siteName); // 打印抬头：优先取系统设置里的「打印抬头」
$printer = $printer ?? ''; // 打印人（当前登录操作人）
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
.meta-lines{margin:0 0 8px}
.meta-line{font-size:13px;color:#444;padding:2px 0}
.meta-line span{margin-right:18px;white-space:nowrap}
.meta-line b{color:#555;font-weight:bold}
table.items-table{width:100%;border-collapse:collapse;margin:6px 0}
table.items-table th,table.items-table td{border:1px solid #999;padding:6px 8px;font-size:12px;text-align:center}
table.items-table th{background:#eee;-webkit-print-color-adjust:exact;print-color-adjust:exact}
table.items-table thead{display:table-header-group} /* 打印跨页时下一页自动重复表头 */
tr.group-total td{font-weight:bold;background:#fcfcfc;-webkit-print-color-adjust:exact;print-color-adjust:exact}
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

<?php if (empty($orders)): ?>
  <div style="text-align:center;color:#999;padding:30px">暂无数据</div>
<?php else:
  // 信息行去重：类型/来源单/往来单位/仓库/日期/状态/备注与上一张相同时只显示一行
  $metaLines = [];
  $lastMeta = null;
  foreach ($orders as $o) {
    $partner = (int)($o['return_type'] ?? 1) === 2 ? ($o['supplier_name'] ?? '-') : ($o['customer_name'] ?? '-');
    $srcNo = (int)($o['return_type'] ?? 1) === 2 ? ($o['inbound_no'] ?? '') : ($o['outbound_no'] ?? '');
    $meta = (int)($o['return_type'] ?? 1) . '|' . $srcNo . '|' . $partner . '|' . ($o['warehouse_name'] ?? '-') . '|' . $o['return_date'] . '|' . (int)$o['status'] . '|' . ($o['remark'] ?? '');
    if ($meta !== $lastMeta) { $lastMeta = $meta; $metaLines[] = $o; }
  }
?>
<div class="meta-lines">
  <?php foreach ($metaLines as $o): $isType2 = (int)($o['return_type'] ?? 1) === 2; ?>
  <div class="meta-line">
    <span><b>类型：</b><?= $isType2 ? '入库退回（退回供应商）' : '出库反回（客户退货入仓）' ?></span>
    <?php $srcNo = $isType2 ? ($o['inbound_no'] ?? '') : ($o['outbound_no'] ?? ''); ?>
    <?php if ($srcNo !== '' && $srcNo !== null): ?><span><b>来源单号：</b><?= e($srcNo) ?></span><?php endif; ?>
    <span><b>退回单号：</b><?= e($o['order_no']) ?></span>
    <span><b>往来单位：</b><?= e($isType2 ? ($o['supplier_name'] ?? '-') : ($o['customer_name'] ?? '-')) ?></span>
    <span><b>仓库：</b><?= e($o['warehouse_name'] ?? '-') ?></span>
    <span><b>打印人：</b><?= e($printer) ?></span>
    <span><b>日期：</b><?= e($o['return_date']) ?></span>
    <span><b>状态：</b><?= (int)$o['status']===1 ? ($isType2 ? '已退回' : '已反回') : ($isType2 ? '待退回' : '待反回') ?></span>
    <?php if (!empty($o['remark'])): ?><span><b>备注：</b><?= e($o['remark']) ?></span><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<table class="items-table">
  <thead><tr>
    <th style="width:40px">#</th><th>商品编码</th><th>商品名称</th><th>规格</th>
    <th style="width:70px">单位</th><th style="width:75px">数量</th><th style="width:90px">单价</th><th style="width:95px">金额</th><th>备注</th>
  </tr></thead>
  <tbody>
  <?php $seq = 0; $grandQty = 0; $grandAmt = 0;
  foreach ($orders as $o):
    $items = $itemsByOrder[$o['id']] ?? [];
    foreach ($items as $it): $seq++; $grandQty += (float)$it['quantity']; $grandAmt += (float)$it['amount']; ?>
    <tr>
      <td><?= $seq ?></td>
      <td><?= e($it['product_code'] ?? '') ?></td>
      <td><?= e($it['product_name'] ?? '') ?></td>
      <td><?= e($it['product_spec'] ?? '') ?></td>
      <td><?= e($it['product_unit'] ?? '') ?></td>
      <td><?= number_format((float)$it['quantity'], 2) ?></td>
      <td><?= number_format((float)$it['unit_price'], 2) ?></td>
      <td><?= number_format((float)$it['amount'], 2) ?></td>
      <td><?= e($it['remark']) ?></td>
    </tr>
    <?php endforeach; ?>
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
<?php endif; ?>
</body>
</html>
