<?php
use Core\Csrf;
$tab = $tab ?? 'list';
?>
<div class="page-head">
  <h2><?= e($title) ?></h2>
  <div class="actions">
  </div>
</div>

<!-- 标签切换 -->
<div style="margin-bottom:14px;display:flex;gap:6px">
  <a class="btn <?= $tab==='list'?'btn-primary':'' ?>" href="<?= url('inventory/index') ?>">📦 库存查询</a>
  <a class="btn <?= $tab==='warning'?'btn-danger':'' ?>" href="<?= url('inventory/warning') ?>">⚠ 库存预警</a>
  <?php if ($tab==='warning' && !empty($globalThreshold)): ?>
    <span style="align-self:center;color:#888;font-size:13px;margin-left:10px">当前阈值：库存 &lt; <?= e($globalThreshold) ?></span>
  <?php elseif ($tab==='warning'): ?>
    <span style="align-self:center;color:#888;font-size:13px;margin-left:10px">预警规则：库存 &lt; 商品最低库存</span>
  <?php endif; ?>
</div>

<div class="toolbar">
  <form method="get" action="<?= url($tab==='warning' ? 'inventory/warning' : 'inventory/index') ?>" class="search-box search-filters">
    <input type="hidden" name="r" value="<?= e($tab==='warning' ? 'inventory/warning' : 'inventory/index') ?>">
    <input type="hidden" name="warehouse_id" value="<?= (int)$filterWarehouseId ?>">
    <input type="text" name="q" value="<?= e($page['search']) ?>" placeholder="搜索商品编码/名称/规格...">
    <select name="category_id" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="0">全部分类</option>
      <?php foreach (($categories ?? []) as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)($filterCategoryId ?? 0)?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">查询</button>
  </form>
  <div class="toolbar-right">
    <select onchange="filterWarehouse(this.value)" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="0">全部仓库</option>
      <?php foreach ($warehouses as $w): ?>
        <option value="<?= (int)$w['id'] ?>" <?= (int)$w['id']===(int)$filterWarehouseId?'selected':'' ?>><?= e($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <span style="color:#999;font-size:13px">共 <?= $page['total'] ?> 条</span>
  </div>
</div>

<div class="table-wrap">
<table class="data-table">
  <thead><tr>
    <th class="col-check"></th>
    <th>仓库编码</th><th>仓库名称</th><th>分类</th><th>商品编码</th><th>商品名称</th>
    <th>规格</th><th>单位</th><th>当前库存</th><th>最低库存</th><th>最高库存</th><th>状态</th><th>操作</th>
  </tr></thead>
  <tbody>
  <?php if (empty($page['items'])): ?>
    <tr class="empty-row"><td colspan="13">暂无数据</td></tr>
  <?php else: foreach ($page['items'] as $r):
    $qty = (float)$r['quantity'];
    $min = (float)($r['min_stock'] ?? 0);
    $max = (float)($r['max_stock'] ?? 0);
    $isLow = $qty < $min;
    $isOver = ($max > 0 && $qty > $max);
    $noStock = empty($r['id']); // 无库存行的新商品（虚拟行）
  ?>
    <tr<?= $isLow ? ' style="background:#fff5f5"' : ($isOver ? ' style="background:#fffbf0"' : '') ?>>
      <td class="col-check"><?php if (!$noStock): ?><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"><?php endif; ?></td>
      <td><?= $noStock ? '-' : e($r['warehouse_code']) ?></td>
      <td><?= $noStock ? '<span style="color:#999">未入库</span>' : e($r['warehouse_name']) ?></td>
      <td><?= e($r['category_name'] ?? '-') ?></td>
      <td><strong><?= e($r['product_code']) ?></strong></td>
      <td><?= e($r['product_name']) ?></td>
      <td><?= e($r['product_spec']) ?></td>
      <td><?= e($r['product_unit']) ?></td>
      <td><span style="font-weight:bold;color:<?= $isLow?'#e74c3c':'#333' ?>"><?= number_format($qty, 2) ?></span></td>
      <td><?= number_format($min, 2) ?></td>
      <td><?= $max>0 ? number_format($max, 2) : '-' ?></td>
      <td>
        <?php if ($isLow): ?><span class="badge badge-off">库存不足</span>
        <?php elseif ($isOver): ?><span class="badge badge-warn">库存过多</span>
        <?php else: ?><span class="badge badge-on">正常</span><?php endif; ?>
      </td>
      <td class="col-actions"><?php if (!$noStock): ?><button class="btn-link" style="color:#2980b9" onclick="viewInvDetail(<?= (int)$r['id'] ?>)">详情</button><?php else: ?><span style="color:#bbb;font-size:12px">待入库</span><?php endif; ?></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php
$baseUrl = url($tab==='warning' ? 'inventory/warning' : 'inventory/index', array_filter(['q' => $page['search'], 'warehouse_id' => $filterWarehouseId, 'category_id' => (int)($filterCategoryId ?? 0)], fn($v) => $v !== '' && $v !== 0));
$totalPages = $page['totalPages']; $cur = $page['page']; $total = $page['total'];
?>
<div class="pagination">
  <div class="info">第 <?= $cur ?> / <?= max(1,$totalPages) ?> 页，共 <?= $total ?> 条</div>
  <div class="pages">
    <?php if ($cur>1): ?><a href="<?= $baseUrl.'&page=1' ?>">«</a><a href="<?= $baseUrl.'&page='.($cur-1) ?>">‹</a><?php else: ?><span class="disabled">«</span><span class="disabled">‹</span><?php endif; ?>
    <?php for ($i=max(1,$cur-2);$i<=min($totalPages,$cur+2);$i++): ?><a href="<?= $baseUrl.'&page='.$i ?>" class="<?= $i==$cur?'current':'' ?>"><?= $i ?></a><?php endfor; ?>
    <?php if ($cur<$totalPages): ?><a href="<?= $baseUrl.'&page='.($cur+1) ?>">›</a><a href="<?= $baseUrl.'&page='.$totalPages ?>">»</a><?php else: ?><span class="disabled">›</span><span class="disabled">»</span><?php endif; ?>
  </div>
</div>

<!-- 库存详情弹窗 -->
<div class="modal-mask" id="invDetailModal">
  <div class="modal">
    <div class="modal-head"><h3 id="invDetailTitle">库存详情</h3><button class="close" type="button">&times;</button></div>
    <div class="modal-body">
      <table class="data-table" style="width:100%"><tbody id="invDetailBody"></tbody></table>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="Modal.close('invDetailModal')">关闭</button></div>
  </div>
</div>

<script>
// 当前筛选条件（不含仓库）
function currentQueryNoWh(){
  var parts = [];
  var q = '<?= urlencode((string)$page['search']) ?>';
  if (q) parts.push('q=' + q);
  var cid = <?= (int)($filterCategoryId ?? 0) ?>;
  if (cid > 0) parts.push('category_id=' + cid);
  return parts.length ? '&' + parts.join('&') : '';
}

// 完整筛选条件（含仓库）
function currentQuery(){
  var wid = <?= (int)$filterWarehouseId ?>;
  var parts = [];
  if (wid > 0) parts.push('warehouse_id=' + wid);
  var rest = currentQueryNoWh();
  if (rest) parts.push(rest.substring(1));
  return parts.length ? '&' + parts.join('&') : '';
}

function filterWarehouse(wid){
  location.href = '<?= url($tab==='warning' ? 'inventory/warning' : 'inventory/index') ?>&warehouse_id=' + wid + currentQueryNoWh();
}

// 批量操作条（库存无编辑）：打印 / 删除；预警页/清单页对应不同打印地址
bindBatch({
  <?php if ($canPrint): ?>printUrl: function(){ return '<?= url($tab==='warning' ? 'inventory/printWarning' : 'inventory/print') ?>' + currentQuery(); },<?php endif; ?>
  deleteUrl: '<?= !empty($canDelete) ? url('inventory/destroy') : '' ?>'
});

// ---- 详情弹窗 ----
function invEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function invRow(label, val){
  return '<tr><td style="width:35%;color:#888;background:#fafafa">'+label+'</td><td>'+(val==null||val===''?'-':val)+'</td></tr>';
}
function viewInvDetail(id){
  Api.get('<?= url('inventory/detail') ?>&id=' + id).then(function(r){
    if (!r || r.success === false || r.id === undefined){ toast((r && r.message) || '加载失败','error'); return; }
    var qty = parseFloat(r.quantity)||0, min = parseFloat(r.min_stock)||0, max = parseFloat(r.max_stock)||0;
    var fmt = function(v){ return Number(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); };
    var status;
    if (qty < min) status = '<span class="badge badge-off">库存不足</span>';
    else if (max > 0 && qty > max) status = '<span class="badge badge-warn">库存过多</span>';
    else status = '<span class="badge badge-on">正常</span>';
    var html = '';
    html += invRow('仓库编码', invEsc(r.warehouse_code));
    html += invRow('仓库名称', invEsc(r.warehouse_name));
    html += invRow('分类', invEsc(r.category_name));
    html += invRow('商品编码', '<strong>'+invEsc(r.product_code)+'</strong>');
    html += invRow('商品名称', invEsc(r.product_name));
    html += invRow('规格', invEsc(r.product_spec));
    html += invRow('单位', invEsc(r.product_unit));
    html += invRow('当前库存', '<span style="font-weight:bold;color:'+(qty<min?'#e74c3c':'#333')+'">'+fmt(qty)+'</span>');
    html += invRow('最低库存', fmt(min));
    html += invRow('最高库存', max > 0 ? fmt(max) : '-');
    html += invRow('状态', status);
    document.getElementById('invDetailBody').innerHTML = html;
    document.getElementById('invDetailTitle').textContent = '库存详情 - ' + (r.product_name || '');
    Modal.open('invDetailModal');
  });
}
</script>
