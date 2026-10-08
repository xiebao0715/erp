<?php
use Core\Csrf;
?>
<div class="page-head">
  <h2>报表统计</h2>
  <div class="actions">
    <?php if ($canPrint): ?><button class="btn" onclick="printReport()">🖨 打印</button><?php endif; ?>
  </div>
</div>

<form method="get" action="<?= url('report/index') ?>" class="toolbar" style="margin-bottom:18px">
  <input type="hidden" name="r" value="report/index">
  <div class="search-box" style="flex:0 0 auto;max-width:none">
    <span style="padding:7px 10px;background:#fafbfc;border:1px solid var(--border);border-right:none;border-radius:4px 0 0 4px;font-size:13px">起止日期：</span>
    <input type="date" name="from" value="<?= e($from) ?>" style="border-radius:0;width:145px;min-width:145px;flex:0 0 145px">
    <span style="padding:7px 4px;background:#fafbfc;border-top:1px solid var(--border);border-bottom:1px solid var(--border)">至</span>
    <input type="date" name="to" value="<?= e($to) ?>" style="border-radius:0 4px 4px 0;width:145px;min-width:145px;flex:0 0 145px">
    <button type="submit" style="border-radius:0 4px 4px 0">查询</button>
  </div>
  <div class="toolbar-right"><span style="color:#888;font-size:13px">日期范围：<?= e($from) ?> ~ <?= e($to) ?></span></div>
</form>

<!-- 汇总卡片 -->
<div class="stat-grid">
  <div class="stat-card green">
    <div class="stat-label">入库单数 / 金额</div>
    <div class="stat-value"><?= (int)($inboundStats['cnt'] ?? 0) ?> / ¥<?= number_format((float)($inboundStats['amount'] ?? 0), 2) ?></div>
  </div>
  <div class="stat-card orange">
    <div class="stat-label">出库单数 / 金额</div>
    <div class="stat-value"><?= (int)($outboundStats['cnt'] ?? 0) ?> / ¥<?= number_format((float)($outboundStats['amount'] ?? 0), 2) ?></div>
  </div>
  <div class="stat-card red">
    <div class="stat-label">当前库存项 / 总量</div>
    <div class="stat-value"><?= (int)($inventoryStats['cnt'] ?? 0) ?> / <?= number_format((float)($inventoryStats['qty'] ?? 0), 2) ?></div>
  </div>
</div>

<?php if ((int)$lowCount > 0): ?>
<div class="alert alert-danger" style="margin-bottom:16px">⚠ 当前有 <strong><?= (int)$lowCount ?></strong> 种商品库存不足，请前往「库存预警」处理。</div>
<?php endif; ?>

<!-- 入库明细表 -->
<h3 style="margin:20px 0 8px;font-size:15px">入库明细（<?= e($from) ?> ~ <?= e($to) ?>）</h3>
<div class="table-wrap">
<table class="data-table">
  <thead><tr><th>单号</th><th>入库日期</th><th>仓库</th><th>供应商</th><th>经办人</th><th>金额</th></tr></thead>
  <tbody>
  <?php if (empty($inboundItems)): ?>
    <tr class="empty-row"><td colspan="6">暂无数据</td></tr>
  <?php else: foreach ($inboundItems as $r): ?>
    <tr>
      <td><strong><?= e($r['order_no']) ?></strong></td>
      <td><?= e($r['inbound_date']) ?></td>
      <td><?= e($r['warehouse_name']) ?></td>
      <td><?= e($r['supplier_name']) ?></td>
      <td><?= e($r['operator']) ?></td>
      <td><span style="color:#27ae60">¥<?= number_format((float)$r['total_amount'], 2) ?></span></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<!-- 出库明细表 -->
<h3 style="margin:24px 0 8px;font-size:15px">出库明细（<?= e($from) ?> ~ <?= e($to) ?>）</h3>
<div class="table-wrap">
<table class="data-table">
  <thead><tr><th>单号</th><th>出库日期</th><th>仓库</th><th>客户</th><th>经办人</th><th>金额</th></tr></thead>
  <tbody>
  <?php if (empty($outboundItems)): ?>
    <tr class="empty-row"><td colspan="6">暂无数据</td></tr>
  <?php else: foreach ($outboundItems as $r): ?>
    <tr>
      <td><strong><?= e($r['order_no']) ?></strong></td>
      <td><?= e($r['outbound_date']) ?></td>
      <td><?= e($r['warehouse_name']) ?></td>
      <td><?= e($r['customer_name']) ?></td>
      <td><?= e($r['operator']) ?></td>
      <td><span style="color:#e74c3c">¥<?= number_format((float)$r['total_amount'], 2) ?></span></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<script>
function printReport(){
  var from = '<?= urlencode((string)$from) ?>';
  var to = '<?= urlencode((string)$to) ?>';
  window.open('<?= url('report/print') ?>&from='+from+'&to='+to, '_blank');
}
</script>
