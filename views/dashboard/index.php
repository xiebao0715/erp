<?php
use Core\Auth;
$cu = Auth::instance();
$currency = '¥';
?>
<div class="page-head">
  <h2>仪表盘</h2>
</div>

<p style="color:#666;margin-bottom:18px">欢迎回来，<strong><?= e($cu->name()) ?></strong>！以下是当前仓库概览。</p>

<!-- 统计卡片 -->
<div class="stat-grid">
  <?php if (isset($stats['warehouses'])): ?>
  <div class="stat-card"><div class="stat-label">启用仓库</div><div class="stat-value"><?= $stats['warehouses'] ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['products'])): ?>
  <div class="stat-card green"><div class="stat-label">启用商品</div><div class="stat-value"><?= $stats['products'] ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['suppliers'])): ?>
  <div class="stat-card orange"><div class="stat-label">供应商</div><div class="stat-value"><?= $stats['suppliers'] ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['customers'])): ?>
  <div class="stat-card"><div class="stat-label">客户</div><div class="stat-value"><?= $stats['customers'] ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['inbound_total'])): ?>
  <div class="stat-card green"><div class="stat-label">总入库数</div><div class="stat-value"><?= $stats['inbound_total'] ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['outbound_total'])): ?>
  <div class="stat-card red"><div class="stat-label">总出库数</div><div class="stat-value"><?= $stats['outbound_total'] ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['inbound_today'])): ?>
  <div class="stat-card green"><div class="stat-label">今日入库单</div><div class="stat-value"><?= $stats['inbound_today'] ?></div><div style="font-size:12px;color:#888;margin-top:4px">金额 <?= $currency ?><?= number_format($stats['inbound_today_amount'],2) ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['outbound_today'])): ?>
  <div class="stat-card red"><div class="stat-label">今日出库单</div><div class="stat-value"><?= $stats['outbound_today'] ?></div><div style="font-size:12px;color:#888;margin-top:4px">金额 <?= $currency ?><?= number_format($stats['outbound_today_amount'],2) ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['production_today'])): ?>
  <div class="stat-card orange"><div class="stat-label">今日生产任务数</div><div class="stat-value"><?= $stats['production_today'] ?></div><div style="font-size:12px;color:#888;margin-top:4px">生产数量 <?= number_format($stats['production_today_qty'],2) ?></div></div>
  <?php endif; ?>
  <?php if (isset($stats['return_today'])): ?>
  <div class="stat-card"><div class="stat-label">今日退回总数</div><div class="stat-value"><?= $stats['return_today'] ?></div><div style="font-size:12px;color:#888;margin-top:4px">金额 <?= $currency ?><?= number_format($stats['return_today_amount'],2) ?></div></div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start" class="dash-grid">
  <!-- 库存预警 -->
  <?php if (!empty($lowStock)): ?>
  <div class="content-card" style="padding:16px">
    <h3 style="font-size:15px;margin-bottom:12px;color:#e67e22">⚠ 库存预警</h3>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>编码</th><th>商品名称</th><th>规格</th><th>库存</th><th>最低库存</th></tr></thead>
      <tbody>
        <?php foreach ($lowStock as $r): ?>
        <tr><td><?= e($r['code']) ?></td><td><?= e($r['name']) ?></td><td><?= e($r['spec']) ?></td><td><span style="color:#e74c3c;font-weight:bold"><?= e($r['stock']) ?></span></td><td><?= e($r['min_stock']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- 最近入库 -->
  <div class="content-card" style="padding:16px">
    <h3 style="font-size:15px;margin-bottom:12px">最近入库单</h3>
    <?php if (empty($recentInbound)): ?>
      <div class="empty-state">暂无数据</div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>单号</th><th>日期</th><th>金额</th><th>状态</th></tr></thead>
      <tbody>
        <?php foreach ($recentInbound as $r): ?>
        <tr><td><?= e($r['order_no']) ?></td><td><?= e($r['inbound_date']) ?></td><td><?= $currency ?><?= number_format($r['total_amount'],2) ?></td><td><?= $r['status']==1?'<span class="badge badge-on">已入库</span>':'<span class="badge badge-off">待入库</span>' ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- 最近出库 -->
  <div class="content-card" style="padding:16px">
    <h3 style="font-size:15px;margin-bottom:12px">最近出库单</h3>
    <?php if (empty($recentOutbound)): ?>
      <div class="empty-state">暂无数据</div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>单号</th><th>日期</th><th>金额</th><th>状态</th></tr></thead>
      <tbody>
        <?php foreach ($recentOutbound as $r): ?>
        <tr><td><?= e($r['order_no']) ?></td><td><?= e($r['outbound_date']) ?></td><td><?= $currency ?><?= number_format($r['total_amount'],2) ?></td><td><?= $r['status']==1?'<span class="badge badge-on">已出库</span>':'<span class="badge badge-off">待出库</span>' ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- 最近退回 -->
  <div class="content-card" style="padding:16px">
    <h3 style="font-size:15px;margin-bottom:12px">最近退回单</h3>
    <?php if (empty($recentReturn)): ?>
      <div class="empty-state">暂无数据</div>
    <?php else: ?>
    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>单号</th><th>类型</th><th>日期</th><th>金额</th><th>状态</th></tr></thead>
      <tbody>
        <?php foreach ($recentReturn as $r): $rt = (int)$r['return_type']; ?>
        <tr>
          <td><?= e($r['order_no']) ?></td>
          <td><?= $rt === 2 ? '<span class="badge badge-warn">入库退回</span>' : '<span class="badge">出库反回</span>' ?></td>
          <td><?= e($r['return_date']) ?></td>
          <td><?= $currency ?><?= number_format($r['total_amount'],2) ?></td>
          <td><?php
            if ((int)$r['status'] === 1) {
              echo $rt === 2 ? '<span class="badge badge-on">已退回</span>' : '<span class="badge badge-on">已反回</span>';
            } else {
              echo $rt === 2 ? '<span class="badge badge-off">待退回</span>' : '<span class="badge badge-off">待反回</span>';
            }
          ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<style>
@media (max-width:960px){ .dash-grid{ grid-template-columns:1fr!important; } }
</style>
