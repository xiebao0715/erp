<?php
/**
 * 通用 CRUD 列表视图（由 BaseCrudController 渲染）
 * 变量: $title, $module, $columns, $formFields, $page, $canCreate, $canEdit, $canDelete, $canPrint
 * 商品模块附加: $categories（分类列表）, $filterCategoryId（当前筛选分类）
 */
use Core\Csrf;
$formId = 'crudForm';
$modalId = 'crudModal';
$isProduct = ($module === 'product');
?>
<div class="page-head">
  <h2><?= e($title) ?></h2>
  <div class="actions">
    <?php if ($isProduct): ?><a class="btn" style="color:#8e44ad" href="<?= url('basic/index') ?>">🗂 分类 / 单位设置</a><?php endif; ?>
    <?php if ($canCreate): ?><button class="btn btn-primary" data-act="create">＋ 新增</button><?php endif; ?>
  </div>
</div>

<div class="toolbar">
  <form method="get" action="<?= url($module . '/index') ?>" class="search-box">
    <input type="hidden" name="r" value="<?= e($module . '/index') ?>">
    <input type="text" name="q" value="<?= e($page['search'] ?? '') ?>" placeholder="搜索...">
    <?php if ($isProduct): ?>
    <select name="category_id" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="0">全部分类</option>
      <?php foreach (($categories ?? []) as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)($filterCategoryId ?? 0)?'selected':'' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="producible" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
      <option value="">全部商品</option>
      <option value="1" <?= ($filterProducible ?? '')==='1'?'selected':'' ?>>仅可生产</option>
      <option value="0" <?= ($filterProducible ?? '')==='0'?'selected':'' ?>>不可生产</option>
    </select>
    <?php endif; ?>
    <button type="submit">搜索</button>
  </form>
  <div class="toolbar-right">
    <span style="color:#999;font-size:13px">共 <?= $page['total'] ?> 条</span>
  </div>
</div>

<div class="table-wrap">
<table class="data-table">
  <thead>
    <tr>
      <th class="col-check"></th>
      <?php foreach ($columns as $col): ?><th><?= e($col['label']) ?></th><?php endforeach; ?>
      <th>操作</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($page['items'])): ?>
    <tr class="empty-row"><td colspan="<?= count($columns) + 2 ?>">暂无数据</td></tr>
  <?php else: foreach ($page['items'] as $row): ?>
    <tr>
      <td class="col-check"><input type="checkbox" class="row-check" value="<?= e($row['id']) ?>"></td>
      <?php foreach ($columns as $col): ?>
        <td><?= renderCell($col, $row) ?></td>
      <?php endforeach; ?>
      <td class="col-actions">
        <button class="btn-link" style="color:#2980b9" data-act="detail" data-id="<?= e($row['id']) ?>">详情</button>
        <?php if ($canEdit): ?><button class="btn-link" data-act="edit" data-id="<?= e($row['id']) ?>">编辑</button><?php endif; ?>
        <?php if ($canDelete): ?><button class="btn-link danger" data-act="delete" data-id="<?= e($row['id']) ?>">删除</button><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php
$totalPages = $page['totalPages']; $cur = $page['page']; $per = $page['perPage']; $total = $page['total'];
$baseUrl = url($module . '/index', array_filter(['q' => $page['search'] ?? '', 'category_id' => (int)($filterCategoryId ?? 0), 'producible' => $filterProducible ?? ''], fn($v) => $v !== '' && $v !== 0));
?>
<div class="pagination">
  <div class="info">第 <?= $cur ?> / <?= max(1,$totalPages) ?> 页，共 <?= $total ?> 条，每页 <?= $per ?> 条</div>
  <div class="pages">
    <?php if ($cur > 1): ?><a href="<?= $baseUrl ?>&page=1">«</a><a href="<?= $baseUrl ?>&page=<?= $cur-1 ?>">‹</a><?php else: ?><span class="disabled">«</span><span class="disabled">‹</span><?php endif; ?>
    <?php
    $start = max(1, $cur - 2); $end = min($totalPages, $cur + 2);
    for ($i = $start; $i <= $end; $i++):
    ?><a href="<?= $baseUrl ?>&page=<?= $i ?>" class="<?= $i==$cur?'current':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($cur < $totalPages): ?><a href="<?= $baseUrl ?>&page=<?= $cur+1 ?>">›</a><a href="<?= $baseUrl ?>&page=<?= $totalPages ?>">»</a><?php else: ?><span class="disabled">›</span><span class="disabled">»</span><?php endif; ?>
  </div>
</div>

<!-- 新增/编辑弹窗 -->
<div class="modal-mask" id="<?= $modalId ?>">
  <div class="modal">
    <div class="modal-head"><h3 id="crudModalTitle">新增</h3><button class="close" type="button">&times;</button></div>
    <form id="<?= $formId ?>" method="post" action="<?= url($module . '/store') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <?php foreach ($formFields as $f): ?>
        <div class="form-group">
          <label><?= e($f['label']) ?><?php if (!empty($f['required'])): ?> <span class="req">*</span><?php endif; ?></label>
          <?php renderField($f); ?>
          <?php if (!empty($f['hint'])): ?><div class="form-hint"><?= e($f['hint']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="Modal.close('<?= $modalId ?>')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
      </div>
    </form>
  </div>
</div>

<!-- 详情弹窗 -->
<div class="modal-mask" id="detailModal">
  <div class="modal">
    <div class="modal-head"><h3 id="detailTitle"><?= e($title) ?> - 详情</h3><button class="close" type="button">&times;</button></div>
    <div class="modal-body">
      <table class="data-table" style="width:100%"><tbody id="detailBody"></tbody></table>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="Modal.close('detailModal')">关闭</button></div>
  </div>
</div>

<?php
function renderCell(array $col, array $row): string {
  $val = $row[$col['key']] ?? '';
  $type = $col['type'] ?? 'text';
  switch ($type) {
    case 'amount':
    case 'price':
      return '<span style="color:#27ae60;font-weight:bold">' . e(number_format((float)$val, 2)) . '</span>';
    case 'number':
      return e(number_format((float)$val, ($type==='number'?0:2)));
    case 'status':
      return $val == 1 ? '<span class="badge badge-on">启用</span>' : '<span class="badge badge-off">停用</span>';
    case 'yesno':
      return $val == 1 ? '<span class="badge badge-on">是</span>' : '<span class="badge badge-off">否</span>';
    case 'badge_admin':
      return $val == 1 ? '<span class="badge badge-admin">管理员</span>' : '<span class="badge">成员</span>';
    case 'order_status':
      $map = [0=>'待处理',1=>'已确认',2=>'已完成',3=>'已取消'];
      $s = (int)$val;
      $cls = [0=>'badge-warn',1=>'badge-admin',2=>'badge-on',3=>'badge-off'][$s] ?? 'badge-off';
      return '<span class="badge '.$cls.'">'.e($map[$s] ?? '未知').'</span>';
    case 'datetime':
      return e($val ? substr((string)$val,0,16) : '-');
    case 'date':
      return e($val ? substr((string)$val,0,10) : '-');
    default:
      return e($val);
  }
}

function renderField(array $f): void {
  $name = e($f['name']);
  $type = $f['type'] ?? 'text';
  $required = !empty($f['required']) ? 'required' : '';
  $ph = 'placeholder="' . e($f['placeholder'] ?? '') . '"';
  switch ($type) {
    case 'textarea':
      echo '<textarea name="'.$name.'" '.$ph.' '.$required.'></textarea>';
      break;
    case 'select':
      echo '<select name="'.$name.'" '.$required.'>';
      echo '<option value="">请选择</option>';
      foreach (($f['options'] ?? []) as $k => $v) {
        echo '<option value="'.e($k).'">'.e($v).'</option>';
      }
      echo '</select>';
      break;
    case 'number':
      echo '<input type="number" step="0.01" name="'.$name.'" '.$ph.' '.$required.'>';
      break;
    case 'date':
      echo '<input type="date" name="'.$name.'" '.$ph.' '.$required.'>';
      break;
    default:
      echo '<input type="text" name="'.$name.'" '.$ph.' '.$required.'>';
  }
}
?>
<script>
window.bindCrud({
  formId: '<?= $formId ?>',
  modalId: '<?= $modalId ?>',
  titleId: 'crudModalTitle',
  storeUrl: '<?= url($module . '/store') ?>',
  updateUrl: '<?= url($module . '/update') ?>',
  editUrl: '<?= url($module . '/edit') ?>',
  destroyUrl: '<?= url($module . '/destroy') ?>'
});

// 当前筛选参数（q / category_id）
function currentQuery(){
  var parts = [];
  var q = '<?= urlencode((string)($page['search'] ?? '')) ?>';
  if (q) parts.push('q=' + q);
  <?php if ($isProduct): ?>
  var cid = <?= (int)($filterCategoryId ?? 0) ?>;
  if (cid > 0) parts.push('category_id=' + cid);
  var prod = '<?= urlencode((string)($filterProducible ?? '')) ?>';
  if (prod) parts.push('producible=' + prod);
  <?php endif; ?>
  return parts.length ? '&' + parts.join('&') : '';
}

// 批量操作条：打印（带当前筛选）/ 编辑（仅一条）/ 删除
bindBatch({
  <?php if ($canPrint): ?>printUrl: function(){ return '<?= url($module . '/print') ?>' + currentQuery(); },<?php endif; ?>
  deleteUrl: '<?= $canDelete ? url($module . '/destroy') : '' ?>'<?php if ($canEdit): ?>,
  editFn: function(id){
    var cb = document.querySelector('.row-check[value="' + id + '"]');
    var btn = cb ? cb.closest('tr').querySelector('[data-act="edit"]') : null;
    if (btn) btn.click();
  }<?php endif; ?>
});

// ---- 详情弹窗：列格式化与列表 renderCell 保持一致 ----
var CRUD_COLUMNS = <?= json_encode($columns, JSON_UNESCAPED_UNICODE) ?>;
function dEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function dNum(v, dec){ return Number(v||0).toLocaleString('en-US',{minimumFractionDigits:dec,maximumFractionDigits:dec}); }
function dCell(col, val){
  switch (col.type || 'text') {
    case 'amount': case 'price':
      return '<span style="color:#27ae60;font-weight:bold">' + dNum(val, 2) + '</span>';
    case 'number':
      return dNum(val, 0);
    case 'status':
      return val == 1 ? '<span class="badge badge-on">启用</span>' : '<span class="badge badge-off">停用</span>';
    case 'yesno':
      return val == 1 ? '<span class="badge badge-on">是</span>' : '<span class="badge badge-off">否</span>';
    case 'badge_admin':
      return val == 1 ? '<span class="badge badge-admin">管理员</span>' : '<span class="badge">成员</span>';
    case 'order_status': {
      var map = {0:'待处理',1:'已确认',2:'已完成',3:'已取消'};
      var cls = {0:'badge-warn',1:'badge-admin',2:'badge-on',3:'badge-off'};
      var s = parseInt(val, 10);
      return '<span class="badge '+(cls[s]||'badge-off')+'">'+dEsc(map[s]||'未知')+'</span>';
    }
    case 'datetime': return dEsc(val ? String(val).substring(0,16) : '-');
    case 'date': return dEsc(val ? String(val).substring(0,10) : '-');
    default: return dEsc(val === '' || val == null ? '-' : val);
  }
}
function viewDetail(id){
  Api.get('<?= url($module . '/detail') ?>&id=' + id).then(function(row){
    if (!row || row.success === false || row.id === undefined){ toast((row && row.message) || '加载失败','error'); return; }
    var html = '';
    CRUD_COLUMNS.forEach(function(col){
      html += '<tr><td style="width:35%;color:#888;background:#fafafa">'+dEsc(col.label)+'</td><td>'+dCell(col, row[col.key])+'</td></tr>';
    });
    document.getElementById('detailBody').innerHTML = html;
    Modal.open('detailModal');
  });
}
document.querySelectorAll('[data-act="detail"]').forEach(function(btn){
  btn.addEventListener('click', function(){ viewDetail(btn.getAttribute('data-id')); });
});
</script>
