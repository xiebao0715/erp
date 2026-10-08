<?php
/**
 * 基础设置：商品分类 + 商品单位（各自独立的新增/编辑/删除）
 * @var array $categories
 * @var array $units
 * @var array $productUsed 单位名称 => 商品数量
 */
use Core\Csrf;
?>
<div class="page-head">
  <h2>基础设置</h2>
  <div class="actions">
    <a class="btn" href="<?= url('product/index') ?>">📦 返回商品管理</a>
  </div>
</div>

<div class="basic-grid">

  <!-- ============ 商品分类 ============ -->
  <div class="basic-card">
    <div class="basic-card-head"><h3>🗂 商品分类</h3><span class="basic-tip">用于商品归类、入库/出库/库存分类筛选</span></div>

    <?php if ($canCreate || $canEdit): ?>
    <form class="basic-form" id="catForm" onsubmit="return saveItem('cat')">
      <input type="hidden" id="cat_id" value="">
      <div class="basic-fields">
        <input type="text" id="cat_name" maxlength="60" placeholder="分类名称（如：五金件）" required>
        <input type="number" id="cat_sort" placeholder="排序" value="0" style="width:80px">
        <button type="submit" class="btn btn-primary" id="cat_btn">＋ 新增</button>
        <button type="button" class="btn" id="cat_cancel" style="display:none" onclick="resetForm('cat')">取消编辑</button>
      </div>
    </form>
    <?php endif; ?>

    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>分类名称</th><th style="width:70px">排序</th><th style="width:130px">操作</th></tr></thead>
      <tbody>
      <?php if (empty($categories)): ?>
        <tr class="empty-row"><td colspan="3">暂无分类，请在上方新增</td></tr>
      <?php else: foreach ($categories as $c): ?>
        <tr>
          <td><strong><?= e($c['name']) ?></strong></td>
          <td><?= (int)$c['sort'] ?></td>
          <td class="col-actions">
            <?php if ($canEdit): ?><button class="btn-link" onclick='editItem("cat", <?= (int)$c['id'] ?>, <?= json_encode($c['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= (int)$c['sort'] ?>)'>编辑</button><?php endif; ?>
            <?php if ($canDelete): ?><button class="btn-link danger" onclick='delItem("cat", <?= (int)$c['id'] ?>, <?= json_encode($c['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>删除</button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- ============ 商品单位 ============ -->
  <div class="basic-card">
    <div class="basic-card-head"><h3>📏 商品单位</h3><span class="basic-tip">用于新增商品时选择计量单位（个/件/箱…）</span></div>

    <?php if ($canCreate || $canEdit): ?>
    <form class="basic-form" id="unitForm" onsubmit="return saveItem('unit')">
      <input type="hidden" id="unit_id" value="">
      <div class="basic-fields">
        <input type="text" id="unit_name" maxlength="10" placeholder="单位名称（如：套）" required>
        <input type="number" id="unit_sort" placeholder="排序" value="0" style="width:80px">
        <button type="submit" class="btn btn-primary" id="unit_btn">＋ 新增</button>
        <button type="button" class="btn" id="unit_cancel" style="display:none" onclick="resetForm('unit')">取消编辑</button>
      </div>
    </form>
    <?php endif; ?>

    <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>单位名称</th><th style="width:70px">排序</th><th style="width:90px">在用商品</th><th style="width:130px">操作</th></tr></thead>
      <tbody>
      <?php if (empty($units)): ?>
        <tr class="empty-row"><td colspan="4">暂无单位，请在上方新增</td></tr>
      <?php else: foreach ($units as $u): $used = (int)($productUsed[$u['name']] ?? 0); ?>
        <tr>
          <td><strong><?= e($u['name']) ?></strong></td>
          <td><?= (int)$u['sort'] ?></td>
          <td><?= $used > 0 ? '<span class="badge badge-admin">' . $used . '</span>' : '<span style="color:#bbb">0</span>' ?></td>
          <td class="col-actions">
            <?php if ($canEdit): ?><button class="btn-link" onclick='editItem("unit", <?= (int)$u['id'] ?>, <?= json_encode($u['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= (int)$u['sort'] ?>)'>编辑</button><?php endif; ?>
            <?php if ($canDelete): ?><button class="btn-link danger" onclick='delItem("unit", <?= (int)$u['id'] ?>, <?= json_encode($u['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>删除</button><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>

</div>

<style>
.basic-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(420px,1fr));gap:18px;align-items:start}
.basic-card{background:#fff;border:1px solid var(--border);border-radius:6px;padding:16px}
.basic-card-head{margin-bottom:12px}
.basic-card-head h3{margin:0 0 4px;font-size:16px}
.basic-tip{color:#999;font-size:12px}
.basic-form{margin-bottom:12px}
.basic-fields{display:flex;gap:8px;flex-wrap:wrap}
.basic-fields input[type=text]{flex:1;min-width:160px;padding:8px 10px;border:1px solid var(--border);border-radius:4px}
.basic-fields input[type=number]{padding:8px 10px;border:1px solid var(--border);border-radius:4px}
</style>

<script>
var BASIC_URLS = {
  cat:  { store: '<?= url('basic/categoryStore') ?>', update: '<?= url('basic/categoryUpdate') ?>', destroy: '<?= url('basic/categoryDestroy') ?>', label: '分类' },
  unit: { store: '<?= url('basic/unitStore') ?>',     update: '<?= url('basic/unitUpdate') ?>',     destroy: '<?= url('basic/unitDestroy') ?>',     label: '单位' }
};

function $(id){ return document.getElementById(id); }

function resetForm(type){
  $(type + '_id').value = '';
  $(type + '_name').value = '';
  $(type + '_sort').value = '0';
  $(type + '_btn').textContent = '＋ 新增';
  $(type + '_cancel').style.display = 'none';
}

function editItem(type, id, name, sort){
  $(type + '_id').value = id;
  $(type + '_name').value = name;
  $(type + '_sort').value = sort;
  $(type + '_btn').textContent = '保存修改';
  $(type + '_cancel').style.display = '';
  $(type + '_name').focus();
}

function saveItem(type){
  var cfg = BASIC_URLS[type];
  var id = $(type + '_id').value;
  var name = $(type + '_name').value.trim();
  if (!name){ toast(cfg.label + '名称不能为空', 'error'); return false; }
  var payload = { name: name, sort: $(type + '_sort').value || '0' };
  var url = id ? cfg.update + '&id=' + id : cfg.store;
  Api.post(url, payload).then(function(res){
    if (res.success){ toast(res.message || '保存成功', 'success'); setTimeout(function(){ location.reload(); }, 500); }
    else toast(res.message || '操作失败', 'error');
  }).catch(function(){ toast('网络异常，请重试', 'error'); });
  return false;
}

function delItem(type, id, name){
  var cfg = BASIC_URLS[type];
  confirmDelete({
    message: '确定要删除' + cfg.label + '「' + name + '」吗？正在被使用时将无法删除。',
    onOk: function(){
      Api.post(cfg.destroy + '&id=' + id).then(function(res){
        if (res.success){ toast(res.message || '删除成功', 'success'); setTimeout(function(){ location.reload(); }, 500); }
        else toast(res.message || '删除失败', 'error');
      }).catch(function(){ toast('网络异常，请重试', 'error'); });
    }
  });
}
</script>
