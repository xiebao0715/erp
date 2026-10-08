<?php
use Core\Auth;
use Core\Csrf;
$cu = Auth::instance();
?>
<div class="page-head">
  <h2>入库管理</h2>
  <div class="actions">
    <?php if ($canCreate): ?><button class="btn btn-primary" id="btnAdd">＋ 新增入库单</button><?php endif; ?>
  </div>
</div>

<div class="toolbar">
  <form method="get" action="<?= url('inbound/index') ?>" class="search-box search-filters">
    <input type="hidden" name="r" value="inbound/index">
    <input type="text" name="q" value="<?= e($page['search']) ?>" placeholder="搜索单号/仓库/供应商...">
    <select name="category_id" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="0">全部分类</option>
      <?php foreach (($categories ?? []) as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)$page['category_id']?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="">全部状态</option>
      <option value="1" <?= $page['status']==='1'?'selected':'' ?>>已入库</option>
      <option value="0" <?= $page['status']==='0'?'selected':'' ?>>待入库</option>
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
    <th>入库单号</th><th>商品名称</th><th>规格型号</th><th>数量</th><th>入库仓库</th><th>供应商</th><th>经办人</th>
    <th>入库日期</th><th>总金额</th><th>状态</th><th>操作</th>
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
      <td><?php if ((int)($r['production_id'] ?? 0) > 0): ?><span class="badge badge-admin" title="由生产任务完工自动生成">生产入库</span><?php else: ?><?= e($r['supplier_name']) ?><?php endif; ?></td>
      <td><?= e($r['operator']) ?></td>
      <td><?= e($r['inbound_date']) ?></td>
      <td><span style="color:#27ae60;font-weight:bold">¥<?= number_format((float)$r['total_amount'], 2) ?></span></td>
      <td>
        <?= (int)$r['status']===1 ? '<span class="badge badge-on">已入库</span>' : '<span class="badge badge-warn">待入库</span>' ?>
        <?php if ((float)($r['return_pending_qty'] ?? 0) > 0): ?><br><span class="badge badge-warn" style="margin-top:3px" title="在退回管理中点「已完成」后扣减库存">待退回 <?= number_format((float)$r['return_pending_qty'], 2) ?></span><?php endif; ?>
        <?php if ((float)($r['return_done_qty'] ?? 0) > 0): ?><br><span class="badge" style="margin-top:3px;background:#f0f0f0;color:#666">已退回 <?= number_format((float)$r['return_done_qty'], 2) ?></span><?php endif; ?>
      </td>
      <td class="col-actions">
        <button class="btn-link" style="color:#2980b9" onclick="viewOrder(<?= (int)$r['id'] ?>)">详情</button>
        <?php if ($canReturn && (int)$r['status']===1 && (int)($r['production_id'] ?? 0) === 0 && (float)($r['total_qty'] ?? 0) - (float)($r['return_pending_qty'] ?? 0) - (float)($r['return_done_qty'] ?? 0) > 0): ?><button class="btn-link" style="color:#e67e22" onclick="openReturn(<?= (int)$r['id'] ?>)">退回</button><?php endif; ?>
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
$baseUrl = url('inbound/index', array_filter(['q' => $page['search'], 'category_id' => $page['category_id'], 'status' => $page['status'] ?? '', 'from' => $page['from'], 'to' => $page['to']], fn($v) => $v !== '' && $v !== null));
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

<!-- 新增/编辑入库单弹窗 -->
<div class="modal-mask" id="orderModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3 id="modalTitle">新增入库单</h3><button class="close" type="button">&times;</button></div>
    <form id="orderForm" method="post" action="<?= url('inbound/store') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <input type="hidden" name="total_amount" id="fldTotal" value="0">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label>入库仓库 <span class="req">*</span></label>
            <select name="warehouse_id" id="fldWarehouse" required>
              <option value="">请选择</option>
              <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>供应商 <span class="req">*</span></label>
            <select name="supplier_id" id="fldSupplier" data-search required>
              <option value="">请选择</option>
              <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>经办人</label><input type="text" value="<?= e($cu->name()) ?>" disabled title="经办人自动记录为当前操作人，不可修改"></div>
          <div class="form-group"><label>入库日期 <span class="req">*</span></label><input type="date" name="inbound_date" id="fldDate" value="<?= e(date('Y-m-d')) ?>" required></div>
          <div class="form-group"><label>状态</label>
            <select name="status" id="fldStatus">
              <option value="0">待入库（暂不更新库存，保存后在列表点「已完成」）</option>
              <option value="1">已入库（保存后立即更新库存）</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>入库明细 <span class="req">*</span></label>
          <div style="overflow-x:auto">
            <table class="items-table" id="itemsTable">
              <thead><tr>
                <th style="width:30%">商品</th><th style="width:14%">数量</th><th style="width:14%">单价</th><th style="width:14%">金额</th><th style="width:22%">备注</th><th style="width:6%">操作</th>
              </tr></thead>
              <tbody id="itemsBody">
                <!-- 行将通过 JS 渲染 -->
              </tbody>
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

<!-- ================= 入库退回弹窗 ================= -->
<div class="modal-mask" id="returnModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3>入库退回（退回给供应商）</h3><button class="close" type="button">&times;</button></div>
    <form id="returnForm" method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div id="returnInfo" style="background:#fdf6ec;border:1px solid #f5dab1;border-radius:4px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#8a6d3b"></div>
        <div class="form-group">
          <label>退回明细（退回数量不能大于本单可退数量）</label>
          <div style="overflow-x:auto">
            <table class="items-table">
              <thead><tr>
                <th>商品</th><th style="width:110px">入库数量</th><th style="width:110px">已退数量</th><th style="width:130px">本次退回 <span class="req">*</span></th><th style="width:110px">单位</th>
              </tr></thead>
              <tbody id="returnBody"></tbody>
            </table>
          </div>
        </div>
        <div class="form-group"><label>备注</label><textarea name="remark" id="returnRemark" placeholder="选填"></textarea></div>
        <div style="color:#999;font-size:12px">保存后生成「待退回」单，暂不扣减库存；请到「退回管理」列表点「已完成」后实际退出仓库。</div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="Modal.close('returnModal')">取消</button>
        <button type="submit" class="btn btn-primary">登记退回</button>
      </div>
    </form>
  </div>
</div>

<script>
var PRODUCTS = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;

function productOptions(selected){
  var html = '<option value="">请选择商品</option>';
  PRODUCTS.forEach(function(p){
    html += '<option value="'+p.id+'" data-cost="'+p.cost+'" data-unit="'+e2(p.unit)+'" data-name="'+e2(p.name)+'" data-spec="'+e2(p.spec)+'"'+(String(p.id)===String(selected)?' selected':'')+'>'+e2(p.code)+' / '+e2(p.name)+(p.spec?' ('+e2(p.spec)+')':'')+'</option>';
  });
  return html;
}
function e2(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

function addItem(pid, qty, price, remark){
  var tr = document.createElement('tr');
  tr.innerHTML =
    '<td><select class="it-product" data-search onchange="onProductChange(this)">'+productOptions(pid||'')+'</select></td>'+
    '<td><input type="number" step="0.01" min="0.01" class="it-qty" value="'+(qty||1)+'" oninput="recalcRow(this)"></td>'+
    '<td><input type="number" step="0.01" min="0" class="it-price" value="'+(price||'')+'" oninput="recalcRow(this)"></td>'+
    '<td class="it-amount">0.00</td>'+
    '<td><input type="text" class="it-remark" value="'+e2(remark||'')+'"></td>'+
    '<td class="col-del"><button type="button" onclick="removeRow(this)">✕</button></td>';
  document.getElementById('itemsBody').appendChild(tr);
  // 触发金额计算
  var qtyInput = tr.querySelector('.it-qty');
  recalcRow(qtyInput);
  // 若有商品且无单价，回填成本价
  if (pid) onProductChange(tr.querySelector('.it-product'), true);
}

function onProductChange(sel, keep){
  var opt = sel.options[sel.selectedIndex];
  var cost = opt ? opt.getAttribute('data-cost') : '';
  var tr = sel.closest('tr');
  var priceInput = tr.querySelector('.it-price');
  if (!keep && priceInput && cost && priceInput.value === ''){
    priceInput.value = cost;
  }
  recalcRow(priceInput || sel);
}

function recalcRow(input){
  var tr = input.closest('tr');
  var qty = parseFloat(tr.querySelector('.it-qty').value) || 0;
  var price = parseFloat(tr.querySelector('.it-price').value) || 0;
  var amount = qty * price;
  tr.querySelector('.it-amount').textContent = amount.toFixed(2);
  recalcTotal();
}

function recalcTotal(){
  var total = 0;
  document.querySelectorAll('#itemsBody tr').forEach(function(tr){
    var amt = parseFloat(tr.querySelector('.it-amount').textContent) || 0;
    total += amt;
  });
  document.getElementById('grandTotal').textContent = '¥' + total.toFixed(2);
  document.getElementById('fldTotal').value = total.toFixed(2);
}

function removeRow(btn){
  btn.closest('tr').remove();
  recalcTotal();
}

function resetForm(){
  var f = document.getElementById('orderForm');
  f.reset();
  f.action = '<?= url('inbound/store') ?>';
  document.getElementById('itemsBody').innerHTML = '';
  document.getElementById('grandTotal').textContent = '¥0.00';
  document.getElementById('fldTotal').value = '0';
  document.getElementById('fldDate').value = '<?= e(date('Y-m-d')) ?>';
  addItem();
}

function openCreate(){
  resetForm();
  document.getElementById('modalTitle').textContent = '新增入库单';
  Modal.open('orderModal');
}

function editOrder(id){
  Api.get('<?= url('inbound/edit') ?>&id='+id).then(function(res){
    if (!res || !res.order){ toast(res.message||'加载失败','error'); return; }
    var f = document.getElementById('orderForm');
    f.reset();
    f.action = '<?= url('inbound/update') ?>&id='+id;
    document.getElementById('itemsBody').innerHTML = '';
    f.querySelector('[name=id]').value = res.order.id;
    document.getElementById('fldWarehouse').value = res.order.warehouse_id;
    document.getElementById('fldSupplier').value = res.order.supplier_id;
    document.getElementById('fldDate').value = res.order.inbound_date;
    document.getElementById('fldStatus').value = res.order.status;
    document.getElementById('fldRemark').value = res.order.remark || '';
    (res.items||[]).forEach(function(it){
      addItem(it.product_id, it.quantity, it.unit_price, it.remark);
    });
    recalcTotal();
    document.getElementById('modalTitle').textContent = '编辑入库单';
    Modal.open('orderModal');
  }).catch(function(){ toast('网络异常','error'); });
}

function delOrder(id){
  confirmDelete({
    message: '确定要删除该入库单吗？已入库单据删除将回滚库存。',
    onOk: function(){
      Api.post('<?= url('inbound/destroy') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'删除成功','success'); setTimeout(function(){ location.reload(); }, 600); }
        else toast(res.message||'删除失败','error');
      });
    }
  });
}

function viewOrder(id){
  window.open('<?= url('inbound/detail') ?>&id='+id, '_blank');
}

<?php if ($canOperate): ?>
function completeOrder(id){
  confirmDelete({
    title: '完成确认',
    message: '确认完成该入库单？完成后商品将实际入库并增加库存。',
    confirmText: '确认完成',
    okClass: 'btn-primary',
    onOk: function(){
      Api.post('<?= url('inbound/complete') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'已完成','success'); setTimeout(function(){ location.reload(); }, 700); }
        else toast(res.message||'操作失败','error');
      });
    }
  });
}
<?php endif; ?>

<?php if ($canReturn): ?>
// ============ 入库退回 ============
var RETURN_DATA = null;
function fmtQty(v){ return (parseFloat(v)||0).toFixed(2).replace(/\.?0+$/,''); }
function openReturn(id){
  Api.get('<?= url('inbound/returnItems') ?>&id='+id).then(function(res){
    if (!res || !res.success){ toast((res&&res.message)||'加载失败','error'); return; }
    RETURN_DATA = res;
    var o = res.order;
    document.getElementById('returnForm').action = '<?= url('inbound/returnStore') ?>&id='+id;
    document.getElementById('returnForm').reset();
    document.getElementById('returnInfo').innerHTML =
      '入库单号：<b>'+e2(o.order_no)+'</b>　入库仓库：<b>'+e2(o.warehouse_name||'-')+'</b>　供应商：<b>'+e2(o.supplier_name||'-')+'</b>　日期：'+e2(o.inbound_date);
    var html = '';
    (res.items||[]).forEach(function(it){
      var disabled = parseFloat(it.remain_qty) <= 0;
      html += '<tr data-item="'+it.item_id+'" data-remain="'+it.remain_qty+'">'+
        '<td>'+e2(it.product_code)+' / '+e2(it.product_name)+(it.product_spec?' ('+e2(it.product_spec)+')':'')+'</td>'+
        '<td>'+fmtQty(it.quantity)+'</td>'+
        '<td>'+fmtQty(it.returned_qty)+'</td>'+
        '<td><input type="number" step="0.01" min="0" max="'+it.remain_qty+'" class="rt-qty" value="" placeholder="≤ '+it.remain_qty+'"'+(disabled?' disabled':'')+' style="width:110px"></td>'+
        '<td>'+e2(it.product_unit||'')+'</td>'+
      '</tr>';
    });
    document.getElementById('returnBody').innerHTML = html;
    Modal.open('returnModal');
  }).catch(function(){ toast('网络异常','error'); });
}
document.getElementById('returnForm').addEventListener('submit', function(e){
  e.preventDefault();
  if (!RETURN_DATA){ return; }
  var fd = new FormData();
  var any = false;
  var rows = document.querySelectorAll('#returnBody tr');
  for (var i=0;i<rows.length;i++){
    var tr = rows[i];
    var input = tr.querySelector('.rt-qty');
    if (!input || input.disabled) continue;
    var qty = parseFloat(input.value) || 0;
    var remain = parseFloat(tr.getAttribute('data-remain')) || 0;
    if (qty < 0){ toast('退回数量不能为负','error'); return; }
    if (qty > remain + 0.00001){ toast('退回数量不能大于可退数量 '+remain,'error'); return; }
    if (qty > 0){ fd.append('items['+tr.getAttribute('data-item')+']', qty); any = true; }
  }
  if (!any){ toast('请至少填写一条退回数量','error'); return; }
  fd.append('remark', document.getElementById('returnRemark').value);
  var btn = this.querySelector('[type=submit]'); var old = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '提交中...';
  Api.post(this.action, fd).then(function(res){
    btn.disabled = false; btn.innerHTML = old;
    if (res.success){
      Modal.close('returnModal');
      toast(res.message||'已登记退回','success');
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
  <?php if ($canPrint): ?>printUrl: function(){ return '<?= url('inbound/print') ?>' + currentQuery(); },<?php endif; ?>
  deleteUrl: '<?= $canDelete ? url('inbound/destroy') : '' ?>'<?php if ($canEdit): ?>,
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
  // 删除可能已存在的 items[]
  fd.delete('items');
  items.forEach(function(it, idx){
    fd.append('items['+idx+'][product_id]', it.product_id);
    fd.append('items['+idx+'][quantity]', it.quantity);
    fd.append('items['+idx+'][unit_price]', it.unit_price);
    fd.append('items['+idx+'][remark]', it.remark);
  });
  // 校验仓库
  if (!fd.get('warehouse_id')){ toast('请选择入库仓库','error'); return; }
  if (!fd.get('supplier_id')){ toast('请选择供应商','error'); return; }

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
