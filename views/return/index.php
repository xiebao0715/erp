<div class="page-head">
  <h2>退回管理</h2>
  <div class="actions">
    <span style="color:#999;font-size:13px">入库退回 / 出库反回请分别从「入库管理」「出库管理」列表发起</span>
  </div>
</div>

<div class="toolbar">
  <form method="get" action="<?= url('return/index') ?>" class="search-box search-filters">
    <input type="hidden" name="r" value="return/index">
    <input type="text" name="q" value="<?= e($page['search']) ?>" placeholder="搜索单号/仓库/往来单位...">
    <select name="category_id" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="0">全部分类</option>
      <?php foreach (($categories ?? []) as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)$page['category_id']?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="">全部状态</option>
      <option value="0" <?= $page['status']==='0'?'selected':'' ?>>待办（待退回 / 待反回）</option>
      <option value="1" <?= $page['status']==='1'?'selected':'' ?>>已完成（已退回 / 已反回）</option>
    </select>
    <select name="return_type" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="">全部类型</option>
      <option value="1" <?= $page['return_type']==='1'?'selected':'' ?>>出库反回（客户退货入仓）</option>
      <option value="2" <?= $page['return_type']==='2'?'selected':'' ?>>入库退回（退回供应商）</option>
    </select>
    <input type="date" name="from" value="<?= e($page['from']) ?>" style="padding:7px 8px;border:1px solid var(--border);border-radius:4px;width:145px" title="起始日期">
    <span style="color:#999;font-size:13px">至</span>
    <input type="date" name="to" value="<?= e($page['to']) ?>" style="padding:7px 8px;border:1px solid var(--border);border-radius:4px;width:145px" title="截止日期">
    <button type="submit">查询</button>
  </form>
  <div class="toolbar-right"><span style="color:#999;font-size:13px">共 <?= $page['total'] ?> 条</span></div>
</div>

<div class="table-wrap">
<table class="data-table">
  <thead><tr>
    <th class="col-check"></th>
    <th>退回单号</th><th>类型</th><th>来源单号</th><th>商品名称</th><th>规格型号</th><th>数量</th><th>仓库</th><th>往来单位</th><th>经办人</th>
    <th>日期</th><th>总金额</th><th>状态</th><th>操作</th>
  </tr></thead>
  <tbody>
  <?php if (empty($page['items'])): ?>
    <tr class="empty-row"><td colspan="14">暂无数据</td></tr>
  <?php else: foreach ($page['items'] as $r): $type = (int)$r['return_type']; ?>
    <tr>
      <td class="col-check"><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
      <td><strong><?= e($r['order_no']) ?></strong></td>
      <td><?= $type === 2 ? '<span class="badge badge-warn">入库退回</span>' : '<span class="badge">出库反回</span>' ?><?php if ((int)($r['production_cancel_id'] ?? 0) > 0): ?><br><span class="badge badge-admin" style="margin-top:3px" title="由生产取消自动生成，仅作台账"><?php
        $cst = (int)($r['cancel_task_status'] ?? 0);
        echo $cst === 1 ? '完成后取消' : ($cst === 2 ? '生产中取消' : '生产取消');
      ?></span><?php endif; ?></td>
      <td><?php
        $srcNo = $type === 2 ? ($r['inbound_no'] ?? '') : ($r['outbound_no'] ?? '');
        if ($srcNo !== '' && $srcNo !== null):
          $srcUrl = $type === 2 ? url('inbound/detail') : url('outbound/detail');
          ?><a href="<?= $srcUrl ?>&id=<?= (int)$r['source_id'] ?>" target="_blank" style="color:#2980b9"><?= e($srcNo) ?></a><?php
        else: ?><span style="color:#bbb">-</span><?php endif; ?>
      </td>
      <td style="max-width:200px"><?= e($r['goods_names'] ?: '-') ?></td>
      <td style="max-width:150px;color:#777"><?= e($r['goods_specs'] ?: '-') ?></td>
      <td><span style="font-weight:bold"><?= number_format((float)($r['total_qty'] ?? 0), 2) ?></span></td>
      <td><?= e($r['warehouse_name']) ?></td>
      <td style="max-width:140px"><?= e(($type == 2 ? $r['supplier_name'] : $r['customer_name']) ?: '-') ?></td>
      <td><?= e($r['operator']) ?></td>
      <td><?= e($r['return_date']) ?></td>
      <td><span style="color:#27ae60;font-weight:bold">¥<?= number_format((float)$r['total_amount'], 2) ?></span></td>
      <td><?php
        if ((int)$r['status'] === 1) {
          echo $type === 2 ? '<span class="badge badge-on">已退回</span>' : '<span class="badge badge-on">已反回</span>';
        } else {
          echo $type === 2 ? '<span class="badge badge-warn">待退回</span>' : '<span class="badge badge-warn">待反回</span>';
        }
      ?></td>
      <td class="col-actions">
        <button class="btn-link" style="color:#2980b9" onclick="viewOrder(<?= (int)$r['id'] ?>)">详情</button>
        <?php if ($canEdit && (int)$r['status']===0): ?><button class="btn-link" style="color:#27ae60;font-weight:bold" onclick="completeOrder(<?= (int)$r['id'] ?>, <?= $type ?>)">已完成</button><?php endif; ?>
        <?php if ($canDelete && (int)($r['production_cancel_id'] ?? 0) === 0): ?><button class="btn-link danger" onclick="delOrder(<?= (int)$r['id'] ?>, <?= $type ?>)">删除</button><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php
$baseUrl = url('return/index', array_filter(['q' => $page['search'], 'category_id' => $page['category_id'], 'status' => $page['status'] ?? '', 'return_type' => $page['return_type'] ?? '', 'from' => $page['from'], 'to' => $page['to']], fn($v) => $v !== '' && $v !== null));
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

<script>
function delOrder(id, type){
  var word = type === 2 ? '退回' : '反回';
  confirmDelete({
    message: '确定要删除该' + word + '单吗？已完成的单据删除将回滚库存。',
    onOk: function(){
      Api.post('<?= url('return/destroy') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'删除成功','success'); setTimeout(function(){ location.reload(); }, 600); }
        else toast(res.message||'删除失败','error');
      });
    }
  });
}

function viewOrder(id){ window.open('<?= url('return/detail') ?>&id='+id, '_blank'); }

<?php if ($canEdit): ?>
function completeOrder(id, type){
  var word = type === 2 ? '退回' : '反回';
  confirmDelete({
    title: '完成确认',
    message: '确认完成该' + word + '单？完成后将实际变动库存：入库退回会扣减仓库库存（库存不足会失败），出库反回会把商品加回仓库。',
    confirmText: '确认完成',
    okClass: 'btn-primary',
    onOk: function(){
      Api.post('<?= url('return/complete') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'已完成','success'); setTimeout(function(){ location.reload(); }, 700); }
        else toast(res.message||'操作失败','error');
      });
    }
  });
}
<?php endif; ?>

// 当前筛选条件
function currentQuery(){
  var parts = [];
  var q = '<?= urlencode((string)$page['search']) ?>';
  if (q) parts.push('q=' + q);
  if (<?= (int)$page['category_id'] ?> > 0) parts.push('category_id=<?= (int)$page['category_id'] ?>');
  var st = '<?= urlencode((string)$page['status']) ?>';
  if (st) parts.push('status=' + st);
  var rt = '<?= urlencode((string)$page['return_type']) ?>';
  if (rt) parts.push('return_type=' + rt);
  var from = '<?= urlencode((string)$page['from']) ?>', to = '<?= urlencode((string)$page['to']) ?>';
  if (from) parts.push('from=' + from);
  if (to) parts.push('to=' + to);
  return parts.length ? '&' + parts.join('&') : '';
}

// 多选批量打印 / 删除
bindBatch({
  <?php if ($canPrint): ?>printUrl: function(){ return '<?= url('return/print') ?>' + currentQuery(); },<?php endif; ?>
  deleteUrl: '<?= $canDelete ? url('return/destroy') : '' ?>'
});
</script>
