<?php
use Core\Auth;
use Core\Csrf;
$cu = Auth::instance();
?>
<div class="page-head">
  <h2>出库管理</h2>
  <div class="actions">
    <?php if ($canCreate): ?><button class="btn btn-primary" id="btnAdd">＋ 新增出库单</button><?php endif; ?>
  </div>
</div>

<div class="toolbar">
  <form method="get" action="<?= url('outbound/index') ?>" class="search-box search-filters">
    <input type="hidden" name="r" value="outbound/index">
    <input type="text" name="q" value="<?= e($page['search']) ?>" placeholder="搜索单号/仓库/客户...">
    <select name="category_id" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="0">全部分类</option>
      <?php foreach (($categories ?? []) as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)$page['category_id']?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="">全部状态</option>
      <option value="1" <?= $page['status']==='1'?'selected':'' ?>>已出库</option>
      <option value="0" <?= $page['status']==='0'?'selected':'' ?>>待出库</option>
    </select>
    <input type="date" name="from" value="<?= e($page['from']) ?>" style="padding:7px 8px;border:1px solid var(--border);border-radius:4px" title="起始日期">
    <span style="color:#999;font-size:13px">至</span>
    <input type="date" name="to" value="<?= e($page['to']) ?>" style="padding:7px 8px;border:1px solid var(--border);border-radius:4px" title="截止日期">
    <button type="submit">查询</button>
  </form>
  <div class="toolbar-right"><span style="color:#999;font-size:13px">共 <?= $page['total'] ?> 条</span></div>
</div>

<div class="table-wrap">
<table class="data-table">
  <thead><tr>
    <th class="col-check"></th>
    <th>出库单号</th><th>商品名称</th><th>规格型号</th><th>数量</th><th>出库仓库</th><th>客户</th><th>经办人</th>
    <th>出库日期</th><th>总金额</th><th>状态</th><th>操作</th>
  </tr></thead>
  <tbody>
  <?php if (empty($page['items'])): ?>
    <tr class="empty-row"><td colspan="12">暂无数据</td></tr>
  <?php else: foreach ($page['items'] as $r): ?>
    <tr>
      <td class="col-check"><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
      <td><strong><?= e($r['order_no']) ?></strong></td>
      <td style="max-width:200px"><?= e($r['goods_names'] ?: '-') ?></td>
      <td style="max-width:150px;color:#777"><?= e($r['goods_specs'] ?: '-') ?></td>
      <td><span style="font-weight:bold"><?= number_format((float)($r['total_qty'] ?? 0), 2) ?></span></td>
      <td><?= e($r['warehouse_name']) ?></td>
      <td><?php if ((int)($r['production_id'] ?? 0) > 0): ?><span class="badge badge-admin" title="由生产任务自动生成">生产领料</span><?php else: ?><?= e($r['customer_name']) ?><?php endif; ?></td>
      <td><?= e($r['operator']) ?></td>
      <td><?= e($r['outbound_date']) ?></td>
      <td><span style="color:#27ae60;font-weight:bold">¥<?= number_format((float)$r['total_amount'], 2) ?></span></td>
      <td>
        <?= (int)$r['status']===1 ? '<span class="badge badge-on">已出库</span>' : '<span class="badge badge-warn">待出库</span>' ?>
        <?php if ((float)($r['reverse_pending_qty'] ?? 0) > 0): ?><br><span class="badge badge-warn" style="margin-top:3px" title="在退回管理中点「已完成」后加回库存">待反回 <?= number_format((float)$r['reverse_pending_qty'], 2) ?></span><?php endif; ?>
        <?php if ((float)($r['reverse_done_qty'] ?? 0) > 0): ?><br><span class="badge" style="margin-top:3px;background:#f0f0f0;color:#666">已反回 <?= number_format((float)$r['reverse_done_qty'], 2) ?></span><?php endif; ?>
      </td>
      <td class="col-actions">
        <button class="btn-link" style="color:#2980b9" onclick="viewOrder(<?= (int)$r['id'] ?>)">详情</button>
        <?php if ($canReverse && (int)$r['status']===1 && (int)($r['production_id'] ?? 0) === 0 && (float)($r['total_qty'] ?? 0) - (float)($r['reverse_pending_qty'] ?? 0) - (float)($r['reverse_done_qty'] ?? 0) > 0): ?><button class="btn-link" style="color:#e67e22" onclick="openReverse(<?= (int)$r['id'] ?>)">反回</button><?php endif; ?>
        <?php if ($canOperate && (int)$r['status']===0 && (int)($r['production_id'] ?? 0) === 0): ?><button class="btn-link" style="color:#27ae60;font-weight:bold" onclick="completeOrder(<?= (int)$r['id'] ?>)">已完成</button><?php endif; ?>
        <?php if ($canEdit && (int)($r['production_id'] ?? 0) === 0): ?><button class="btn-link" onclick="editOrder(<?= (int)$r['id'] ?>)">编辑</button><?php endif; ?>
        <?php if ($canDelete && (int)($r['production_id'] ?? 0) === 0): ?><button class="btn-link danger" onclick="delOrder(<?= (int)$r['id'] ?>)">删除</button><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php
$baseUrl = url('outbound/index', array_filter(['q' => $page['search'], 'category_id' => $page['category_id'], 'status' => $page['status'] ?? '', 'from' => $page['from'], 'to' => $page['to']], fn($v) => $v !== '' && $v !== null));
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

<!-- 新增/编辑出库单弹窗 -->
<div class="modal-mask" id="orderModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3 id="modalTitle">新增出库单</h3><button class="close" type="button">&times;</button></div>
    <form id="orderForm" method="post" action="<?= url('outbound/store') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <input type="hidden" name="total_amount" id="fldTotal" value="0">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label>出库仓库 <span class="req">*</span></label>
            <select name="warehouse_id" id="fldWarehouse" required>
              <option value="">请选择</option>
              <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>客户 <span class="req">*</span></label>
            <select name="customer_id" id="fldCustomer" data-search required>
              <option value="">请选择</option>
              <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>经办人</label><input type="text" value="<?= e($cu->name()) ?>" disabled title="经办人自动记录为当前操作人，不可修改"></div>
          <div class="form-group"><label>出库日期 <span class="req">*</span></label><input type="date" name="outbound_date" id="fldDate" value="<?= e(date('Y-m-d')) ?>" required></div>
          <div class="form-group"><label>状态</label>
            <select name="status" id="fldStatus">
              <option value="0">待出库（暂不扣减库存，保存后在列表点「已完成」）</option>
              <option value="1">已出库（保存后立即扣减库存）</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>出库明细 <span class="req">*</span></label>
          <div style="overflow-x:auto">
            <table class="items-table" id="itemsTable">
              <thead><tr>
                <th style="width:26%">商品</th><th style="width:12%">数量</th><th style="width:12%">单价</th><th style="width:12%">金额</th><th style="width:12%">当前库存</th><th style="width:20%">备注</th><th style="width:6%">操作</th>
              </tr></thead>
              <tbody id="itemsBody"></tbody>
            </table>
          </div>
          <div style="margin-top:8px"><button type="button" class="btn btn-sm" onclick="addItem()">＋ 添加一行</button></div>
          <div class="totals">合计：<span class="total-amount" id="grandTotal">¥0.00</span></div>
        </div>

        <div class="form-group"><label>备注</label><textarea name="remark" id="fldRemark"></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="Modal.close('orderModal')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
      </div>
    </form>
  </div>
</div>

<!-- ================= 出库反回弹窗 ================= -->
<div class="modal-mask" id="reverseModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3>出库反回（客户退回，商品入回仓库）</h3><button class="close" type="button">&times;</button></div>
    <form id="reverseForm" method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div id="reverseInfo" style="background:#fdf6ec;border:1px solid #f5dab1;border-radius:4px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#8a6d3b"></div>
        <div class="form-group">
          <label>反回明细（反回数量不能大于本单可反回数量）</label>
          <div style="overflow-x:auto">
            <table class="items-table">
              <thead><tr>
                <th>商品</th><th style="width:110px">出库数量</th><th style="width:110px">已反回</th><th style="width:130px">本次反回 <span class="req">*</span></th><th style="width:110px">单位</th>
              </tr></thead>
              <tbody id="reverseBody"></tbody>
            </table>
          </div>
        </div>
        <div class="form-group"><label>备注</label><textarea name="remark" id="reverseRemark" placeholder="选填"></textarea></div>
        <div style="color:#999;font-size:12px">保存后生成「待反回」单，暂不加回库存；请到「退回管理」列表点「已完成」后商品实际入回仓库。</div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="Modal.close('reverseModal')">取消</button>
        <button type="submit" class="btn btn-primary">登记反回</button>
      </div>
    </form>
  </div>
</div>

<script>
var PRODUCTS = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;

function productOptions(selected){
  var html = '<option value="">请选择商品</option>';
  PRODUCTS.forEach(function(p){
    html += '<option value="'+p.id+'" data-price="'+p.price+'" data-unit="'+e2(p.unit)+'" data-spec="'+e2(p.spec)+'" data-stock="'+(p.stock_total||0)+'"'+(String(p.id)===String(selected)?' selected':'')+'>'+e2(p.code)+' / '+e2(p.name)+(p.spec?' ('+e2(p.spec)+')':'')+'</option>';
  });
  return html;
}
function e2(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

// 商品在指定仓库的当前库存缓存（wid:pid → 数量）
var STOCK_CACHE = {};
function totalStock(pid){
  var v = 0;
  PRODUCTS.forEach(function(p){ if (String(p.id) === String(pid)) v = parseFloat(p.stock_total) || 0; });
  return v;
}
// 刷新某行「当前库存」单元格：已选仓库时查询仓库库存，否则显示全部仓库合计
function refreshStockCell(tr){
  var cell = tr.querySelector('.it-stock');
  if (!cell) return;
  var pid = tr.querySelector('.it-product').value;
  var wid = document.getElementById('fldWarehouse').value;
  if (!pid){ cell.textContent = '-'; cell.removeAttribute('data-qty'); updateStockWarn(tr); return; }
  var key = wid + ':' + pid;
  function paint(qty, label){
    cell.setAttribute('data-qty', qty);
    cell.innerHTML = label;
    updateStockWarn(tr);
  }
  if (STOCK_CACHE[key] !== undefined){ paint(STOCK_CACHE[key], stockLabel(STOCK_CACHE[key])); return; }
  if (!wid){
    var t = totalStock(pid);
    STOCK_CACHE[key] = t;
    paint(t, stockLabel(t));
    return;
  }
  cell.textContent = '查询中...';
  Api.get('<?= url('inventory/stock') ?>&product_id=' + pid + '&warehouse_id=' + wid).then(function(res){
    var q = res && res.success === false ? 0 : parseFloat(res.quantity) || 0;
    STOCK_CACHE[key] = q;
    paint(q, stockLabel(q));
  }).catch(function(){ paint(0, '<span style="color:#e67e22">?</span>'); });
}
function stockLabel(q){
  return '<span style="font-weight:bold;color:' + (q > 0 ? '#333' : '#e74c3c') + '">' + (parseFloat(q) || 0).toFixed(2) + '</span>';
}
// 数量超过当前库存时红色预警
function updateStockWarn(tr){
  var cell = tr.querySelector('.it-stock');
  if (!cell) return;
  var qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
  var stock = parseFloat(cell.getAttribute('data-qty'));
  if (isNaN(stock)) return;
  if (qty > stock){
    cell.innerHTML = '<span style="font-weight:bold;color:#e74c3c">' + stock.toFixed(2) + '（库存不足）</span>';
  } else {
    cell.innerHTML = stockLabel(stock);
  }
}

function addItem(pid, qty, price, remark){
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td><select class="it-product" data-search onchange="onProductChange(this)">'+productOptions(pid||'')+'</select></td>'+
    '<td><input type="number" step="0.01" min="0.01" class="it-qty" value="'+(qty||1)+'" oninput="recalcRow(this)"></td>'+
    '<td><input type="number" step="0.01" min="0" class="it-price" value="'+(price||'')+'" oninput="recalcRow(this)"></td>'+
    '<td class="it-amount">0.00</td>'+
    '<td class="it-stock">-</td>'+
    '<td><input type="text" class="it-remark" value="'+e2(remark||'')+'"></td>'+
    '<td class="col-del"><button type="button" onclick="removeRow(this)">✕</button></td>';
  document.getElementById('itemsBody').appendChild(tr);
  recalcRow(tr.querySelector('.it-qty'));
  if (pid) onProductChange(tr.querySelector('.it-product'), true);
  else refreshStockCell(tr);
}

function onProductChange(sel, keep){
  var opt = sel.options[sel.selectedIndex];
  var price = opt ? opt.getAttribute('data-price') : '';
  var tr = sel.closest('tr');
  var priceInput = tr.querySelector('.it-price');
  if (!keep && priceInput && price && priceInput.value === '') priceInput.value = price;
  refreshStockCell(tr);
  recalcRow(priceInput || sel);
}

function recalcRow(input){
  var tr = input.closest('tr');
  var qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
  var price = parseFloat(tr.querySelector('.it-price').value) || 0;
  tr.querySelector('.it-amount').textContent = (qty * price).toFixed(2);
  updateStockWarn(tr);
  recalcTotal();
}

// 切换仓库后刷新所有行的库存显示
document.getElementById('fldWarehouse').addEventListener('change', function(){
  document.querySelectorAll('#itemsBody tr').forEach(function(tr){ refreshStockCell(tr); });
});

function recalcTotal(){
  var total = 0;
  document.querySelectorAll('#itemsBody tr').forEach(function(tr){
    total += parseFloat(tr.querySelector('.it-amount').textContent) || 0;
  });
  document.getElementById('grandTotal').textContent = '¥' + total.toFixed(2);
  document.getElementById('fldTotal').value = total.toFixed(2);
}

function removeRow(btn){ btn.closest('tr').remove(); recalcTotal(); }

function resetForm(){
  var f = document.getElementById('orderForm');
  f.reset();
  f.action = '<?= url('outbound/store') ?>';
  document.getElementById('itemsBody').innerHTML = '';
  document.getElementById('grandTotal').textContent = '¥0.00';
  document.getElementById('fldTotal').value = '0';
  document.getElementById('fldDate').value = '<?= e(date('Y-m-d')) ?>';
  addItem();
}

function openCreate(){
  resetForm();
  document.getElementById('modalTitle').textContent = '新增出库单';
  Modal.open('orderModal');
}

function editOrder(id){
  Api.get('<?= url('outbound/edit') ?>&id='+id).then(function(res){
    if (!res || !res.order){ toast(res.message||'加载失败','error'); return; }
    var f = document.getElementById('orderForm');
    f.reset();
    f.action = '<?= url('outbound/update') ?>&id='+id;
    document.getElementById('itemsBody').innerHTML = '';
    f.querySelector('[name=id]').value = res.order.id;
    document.getElementById('fldWarehouse').value = res.order.warehouse_id;
    document.getElementById('fldCustomer').value = res.order.customer_id;
    document.getElementById('fldDate').value = res.order.outbound_date;
    document.getElementById('fldStatus').value = res.order.status;
    document.getElementById('fldRemark').value = res.order.remark || '';
    (res.items||[]).forEach(function(it){
      addItem(it.product_id, it.quantity, it.unit_price, it.remark);
    });
    recalcTotal();
    document.getElementById('modalTitle').textContent = '编辑出库单';
    Modal.open('orderModal');
  }).catch(function(){ toast('网络异常','error'); });
}

function delOrder(id){
  confirmDelete({
    message: '确定要删除该出库单吗？已出库单据删除将回滚库存。',
    onOk: function(){
      Api.post('<?= url('outbound/destroy') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'删除成功','success'); setTimeout(function(){ location.reload(); }, 600); }
        else toast(res.message||'删除失败','error');
      });
    }
  });
}

function viewOrder(id){ window.open('<?= url('outbound/detail') ?>&id='+id, '_blank'); }

<?php if ($canOperate): ?>
function completeOrder(id){
  confirmDelete({
    title: '完成确认',
    message: '确认完成该出库单？完成后将按明细实际扣减库存，库存不足会失败。',
    confirmText: '确认完成',
    okClass: 'btn-primary',
    onOk: function(){
      Api.post('<?= url('outbound/complete') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'已完成','success'); setTimeout(function(){ location.reload(); }, 700); }
        else toast(res.message||'操作失败','error');
      });
    }
  });
}
<?php endif; ?>

<?php if ($canReverse): ?>
// ============ 出库反回 ============
function fmtQtyR(v){ return (parseFloat(v)||0).toFixed(2).replace(/\.?0+$/,''); }
function openReverse(id){
  Api.get('<?= url('outbound/reverseItems') ?>&id='+id).then(function(res){
    if (!res || !res.success){ toast((res&&res.message)||'加载失败','error'); return; }
    var o = res.order;
    document.getElementById('reverseForm').action = '<?= url('outbound/reverseStore') ?>&id='+id;
    document.getElementById('reverseForm').reset();
    document.getElementById('reverseInfo').innerHTML =
      '出库单号：<b>'+e2(o.order_no)+'</b>　出库仓库：<b>'+e2(o.warehouse_name||'-')+'</b>　客户：<b>'+e2(o.customer_name||'-')+'</b>　日期：'+e2(o.outbound_date);
    var html = '';
    (res.items||[]).forEach(function(it){
      var disabled = parseFloat(it.remain_qty) <= 0;
      html += '<tr data-item="'+it.item_id+'" data-remain="'+it.remain_qty+'">'+
        '<td>'+e2(it.product_code)+' / '+e2(it.product_name)+(it.product_spec?' ('+e2(it.product_spec)+')':'')+'</td>'+
        '<td>'+fmtQtyR(it.quantity)+'</td>'+
        '<td>'+fmtQtyR(it.returned_qty)+'</td>'+
        '<td><input type="number" step="0.01" min="0" max="'+it.remain_qty+'" class="rv-qty" value="" placeholder="≤ '+it.remain_qty+'"'+(disabled?' disabled':'')+' style="width:110px"></td>'+
        '<td>'+e2(it.product_unit||'')+'</td>'+
      '</tr>';
    });
    document.getElementById('reverseBody').innerHTML = html;
    Modal.open('reverseModal');
  }).catch(function(){ toast('网络异常','error'); });
}
document.getElementById('reverseForm').addEventListener('submit', function(e){
  e.preventDefault();
  var fd = new FormData();
  var any = false;
  var rows = document.querySelectorAll('#reverseBody tr');
  for (var i=0;i<rows.length;i++){
    var tr = rows[i];
    var input = tr.querySelector('.rv-qty');
    if (!input || input.disabled) continue;
    var qty = parseFloat(input.value) || 0;
    var remain = parseFloat(tr.getAttribute('data-remain')) || 0;
    if (qty < 0){ toast('反回数量不能为负','error'); return; }
    if (qty > remain + 0.00001){ toast('反回数量不能大于可反回数量 '+remain,'error'); return; }
    if (qty > 0){ fd.append('items['+tr.getAttribute('data-item')+']', qty); any = true; }
  }
  if (!any){ toast('请至少填写一条反回数量','error'); return; }
  fd.append('remark', document.getElementById('reverseRemark').value);
  var btn = this.querySelector('[type=submit]'); var old = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '提交中...';
  Api.post(this.action, fd).then(function(res){
    btn.disabled = false; btn.innerHTML = old;
    if (res.success){
      Modal.close('reverseModal');
      toast(res.message||'已登记反回','success');
      setTimeout(function(){ location.reload(); }, 800);
    } else toast(res.message||'操作失败','error');
  }).catch(function(){ btn.disabled = false; btn.innerHTML = old; toast('网络异常','error'); });
});
<?php endif; ?>

// 当前筛选条件
function currentQuery(){
  var parts = [];
  var q = '<?= urlencode((string)$page['search']) ?>';
  if (q) parts.push('q=' + q);
  if (<?= (int)$page['category_id'] ?> > 0) parts.push('category_id=<?= (int)$page['category_id'] ?>');
  var from = '<?= urlencode((string)$page['from']) ?>', to = '<?= urlencode((string)$page['to']) ?>';
  if (from) parts.push('from=' + from);
  if (to) parts.push('to=' + to);
  return parts.length ? '&' + parts.join('&') : '';
}

// 多选批量打印 / 删除
bindBatch({
  <?php if ($canPrint): ?>printUrl: function(){ return '<?= url('outbound/print') ?>' + currentQuery(); },<?php endif; ?>
  deleteUrl: '<?= $canDelete ? url('outbound/destroy') : '' ?>'<?php if ($canEdit): ?>,
  editFn: function(id){ editOrder(id); }<?php endif; ?>
});

// 表单提交：组装 items 数据
document.getElementById('orderForm').addEventListener('submit', function(e){
  e.preventDefault();
  var items = [];
  var rows = document.querySelectorAll('#itemsBody tr');
  if (rows.length === 0){ toast('请至少添加一条明细','error'); return; }
  for (var i=0;i<rows.length;i++){
    var tr = rows[i];
    var pid = tr.querySelector('.it-product').value;
    var qty = tr.querySelector('.it-qty').value;
    var price = tr.querySelector('.it-price').value;
    var remark = tr.querySelector('.it-remark').value;
    if (!pid){ toast('第 '+(i+1)+' 行请选择商品','error'); return; }
    if (!qty || parseFloat(qty)<=0){ toast('第 '+(i+1)+' 行数量必须大于 0','error'); return; }
    items.push({ product_id: pid, quantity: qty, unit_price: price, remark: remark });
  }
  var fd = new FormData(this);
  fd.delete('items');
  items.forEach(function(it, idx){
    fd.append('items['+idx+'][product_id]', it.product_id);
    fd.append('items['+idx+'][quantity]', it.quantity);
    fd.append('items['+idx+'][unit_price]', it.unit_price);
    fd.append('items['+idx+'][remark]', it.remark);
  });
  if (!fd.get('warehouse_id')){ toast('请选择出库仓库','error'); return; }
  if (!fd.get('customer_id')){ toast('请选择客户','error'); return; }

  var btn = this.querySelector('[type=submit]'); var old = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '提交中...';
  Api.post(this.action, fd).then(function(res){
    btn.disabled = false; btn.innerHTML = old;
    if (res.success){
      toast(res.message||'保存成功','success');
      Modal.close('orderModal');
      setTimeout(function(){ location.reload(); }, 600);
    } else {
      toast(res.message||'操作失败，请重试','error');
    }
  }).catch(function(){
    btn.disabled = false; btn.innerHTML = old;
    toast('网络异常，请重试','error');
  });
});

document.getElementById('btnAdd').addEventListener('click', openCreate);
</script>
