<?php
use Core\Auth;
use Core\Csrf;
$cu = Auth::instance();
?>
<div class="page-head">
  <h2>成员管理</h2>
  <div class="actions">
    <button class="btn btn-primary" id="btnAddUser">＋ 添加成员</button>
  </div>
</div>

<div class="toolbar">
  <form method="get" action="<?= url('user/index') ?>" class="search-box">
    <input type="hidden" name="r" value="user/index">
    <input type="text" name="q" value="<?= e($page['search']) ?>" placeholder="搜索用户名/姓名...">
    <button type="submit">搜索</button>
  </form>
  <div class="toolbar-right"><span style="color:#999;font-size:13px">共 <?= $page['total'] ?> 人</span></div>
</div>

<div class="table-wrap">
<table class="data-table">
  <thead><tr><th class="col-check"></th><th>ID</th><th>用户名</th><th>姓名</th><th>角色</th><th>电话</th><th>状态</th><th>最近登录</th><th>创建时间</th><th>操作</th></tr></thead>
  <tbody>
  <?php if (empty($page['items'])): ?>
    <tr class="empty-row"><td colspan="10">暂无数据</td></tr>
  <?php else: foreach ($page['items'] as $r): ?>
    <tr>
      <td class="col-check"><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
      <td><?= e($r['id']) ?></td>
      <td><strong><?= e($r['username']) ?></strong></td>
      <td><?= e($r['name']) ?></td>
      <td><?= (int)$r['is_admin']===1 ? '<span class="badge badge-admin">管理员</span>' : '<span class="badge">成员</span>' ?></td>
      <td><?= e($r['phone']) ?></td>
      <td><?= (int)$r['status']===1 ? '<span class="badge badge-on">启用</span>' : '<span class="badge badge-off">禁用</span>' ?></td>
      <td><?= e($r['last_login'] ? substr($r['last_login'],0,16) : '-') ?></td>
      <td><?= e(substr($r['created_at'],0,10)) ?></td>
      <td class="col-actions">
        <button class="btn-link" style="color:#2980b9" onclick="viewUserDetail(<?= (int)$r['id'] ?>)">详情</button>
        <button class="btn-link" onclick="editUser(<?= (int)$r['id'] ?>)">编辑</button>
        <?php if ((int)$r['is_admin'] !== 1): ?>
          <button class="btn-link" style="color:#8e44ad" onclick="openPerms(<?= (int)$r['id'] ?>)">权限</button>
          <button class="btn-link" style="color:#7f8c8d" onclick='openPermLogs(<?= (int)$r['id'] ?>, <?= json_encode($r['name'] !== '' ? $r['name'] : $r['username'], JSON_UNESCAPED_UNICODE) ?>)'>日志</button>
          <button class="btn-link danger" onclick="delUser(<?= (int)$r['id'] ?>)">删除</button>
        <?php else: ?>
          <span style="color:#ccc;font-size:12px">管理员</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>
</div>

<?php
$baseUrl = url('user/index', ['q' => $page['search']]);
$totalPages = $page['totalPages']; $cur = $page['page']; $per = $page['perPage']; $total = $page['total'];
?>
<div class="pagination">
  <div class="info">第 <?= $cur ?> / <?= max(1,$totalPages) ?> 页，共 <?= $total ?> 条</div>
  <div class="pages">
    <?php if ($cur>1): ?><a href="<?= $baseUrl.'&page=1' ?>">«</a><a href="<?= $baseUrl.'&page='.($cur-1) ?>">‹</a><?php else: ?><span class="disabled">«</span><span class="disabled">‹</span><?php endif; ?>
    <?php for ($i=max(1,$cur-2);$i<=min($totalPages,$cur+2);$i++): ?><a href="<?= $baseUrl.'&page='.$i ?>" class="<?= $i==$cur?'current':'' ?>"><?= $i ?></a><?php endfor; ?>
    <?php if ($cur<$totalPages): ?><a href="<?= $baseUrl.'&page='.($cur+1) ?>">›</a><a href="<?= $baseUrl.'&page='.$totalPages ?>">»</a><?php else: ?><span class="disabled">›</span><span class="disabled">»</span><?php endif; ?>
  </div>
</div>

<!-- 新增/编辑成员弹窗 -->
<div class="modal-mask" id="userModal">
  <div class="modal">
    <div class="modal-head"><h3 id="userModalTitle">添加成员</h3><button class="close" type="button">&times;</button></div>
    <form id="userForm" method="post" action="<?= url('user/store') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group"><label>用户名 <span class="req">*</span></label><input type="text" name="username" required></div>
          <div class="form-group"><label>姓名 <span class="req">*</span></label><input type="text" name="name" required></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>密码 <span id="pwdReq" class="req">*</span></label><input type="password" name="password"><div class="form-hint" id="pwdHint">至少 6 位</div></div>
          <div class="form-group"><label>电话</label><input type="text" name="phone"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>角色</label><select name="is_admin"><option value="0">普通成员</option><option value="1">管理员</option></select></div>
          <div class="form-group"><label>状态</label><select name="status"><option value="1">启用</option><option value="0">禁用</option></select></div>
        </div>
      </div>
      <div class="modal-foot"><button type="button" class="btn" onclick="Modal.close('userModal')">取消</button><button type="submit" class="btn btn-primary">保存</button></div>
    </form>
  </div>
</div>

<!-- 权限设置弹窗 -->
<div class="modal-mask" id="permModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3>权限设置 - <span id="permUserName"></span></h3><button class="close" type="button">&times;</button></div>
    <form id="permForm" method="post" action="<?= url('user/savePermissions') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" id="permUserId" value="">
      <input type="hidden" name="perms" id="permsJson" value="">
      <div class="modal-body">
        <div class="alert alert-info" style="margin-bottom:14px">勾选成员可访问的模块及操作；管理员拥有全部权限，无需设置。</div>
        <div class="perm-grid" id="permGrid"></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="selectAllPerms(true)">全选</button>
        <button type="button" class="btn" onclick="selectAllPerms(false)">清空</button>
        <button type="button" class="btn" onclick="Modal.close('permModal')">取消</button>
        <button type="submit" class="btn btn-primary">保存权限</button>
      </div>
    </form>
  </div>
</div>

<!-- 权限分配日志弹窗 -->
<div class="modal-mask" id="permLogModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3>权限分配日志 - <span id="permLogUserName"></span></h3><button class="close" type="button">&times;</button></div>
    <div class="modal-body">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th style="width:150px">时间</th><th style="width:110px">操作人</th><th>变更内容</th></tr></thead>
          <tbody id="permLogBody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="Modal.close('permLogModal')">关闭</button></div>
  </div>
</div>

<!-- 成员详情弹窗 -->
<div class="modal-mask" id="userDetailModal">
  <div class="modal">
    <div class="modal-head"><h3 id="userDetailTitle">成员详情</h3><button class="close" type="button">&times;</button></div>
    <div class="modal-body">
      <table class="data-table" style="width:100%"><tbody id="userDetailBody"></tbody></table>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="Modal.close('userDetailModal')">关闭</button></div>
  </div>
</div>

<script>
var permModules = <?= json_encode($modules, JSON_UNESCAPED_UNICODE) ?>;
var permActions = <?= json_encode($actions, JSON_UNESCAPED_UNICODE) ?>;
var permModuleActions = <?= json_encode($moduleActions ?? [], JSON_UNESCAPED_UNICODE) ?>;
var permActionKeys = Object.keys(permActions);
// 各模块仅显示其实际支持的动作（如仪表盘只有「查看」）
function moduleActionKeys(mkey){
  var allowed = permModuleActions[mkey];
  if (!allowed || !allowed.length) return [];
  return permActionKeys.filter(function(akey){ return allowed.indexOf(akey) >= 0; });
}

function buildPermGrid(perms){
  var html = '';
  Object.keys(permModules).forEach(function(mkey){
    var label = permModules[mkey];
    var keys = moduleActionKeys(mkey);
    var selected = (perms[mkey] || []).filter(function(a){ return keys.indexOf(a) >= 0; });
    html += '<div class="perm-card">';
    html += '<div class="perm-card-head"><span>'+label+'</span><label style="font-weight:normal;font-size:12px"><input type="checkbox" class="mod-all" data-mod="'+mkey+'" '+ (keys.length>0 && selected.length===keys.length?'checked':'') +'> 全选</label></div>';
    html += '<div class="perm-actions">';
    keys.forEach(function(akey){
      var chk = selected.indexOf(akey)>=0 ? 'checked' : '';
      html += '<label class="perm-chk"><input type="checkbox" class="perm-chk-input" data-mod="'+mkey+'" data-act="'+akey+'" '+chk+'> '+permActions[akey]+'</label>';
    });
    html += '</div></div>';
  });
  document.getElementById('permGrid').innerHTML = html;
  // 模块全选联动
  document.querySelectorAll('.mod-all').forEach(function(cb){
    cb.addEventListener('change', function(){
      var m = cb.getAttribute('data-mod');
      document.querySelectorAll('.perm-chk-input[data-mod="'+m+'"]').forEach(function(c){ c.checked = cb.checked; });
    });
  });
  document.querySelectorAll('.perm-chk-input').forEach(function(cb){
    cb.addEventListener('change', function(){
      var m = cb.getAttribute('data-mod');
      var all = document.querySelectorAll('.perm-chk-input[data-mod="'+m+'"]');
      var checked = document.querySelectorAll('.perm-chk-input[data-mod="'+m+'"]:checked');
      var modAll = document.querySelector('.mod-all[data-mod="'+m+'"]');
      if (modAll) modAll.checked = (all.length===checked.length);
    });
  });
}

function collectPerms(){
  var perms = {};
  document.querySelectorAll('.perm-chk-input:checked').forEach(function(cb){
    var m = cb.getAttribute('data-mod'); var a = cb.getAttribute('data-act');
    if (!perms[m]) perms[m] = [];
    perms[m].push(a);
  });
  return perms;
}

function selectAllPerms(check){
  document.querySelectorAll('.perm-chk-input').forEach(function(cb){ cb.checked = check; });
  document.querySelectorAll('.mod-all').forEach(function(cb){ cb.checked = check; });
}

// 打开权限弹窗
function openPerms(id){
  Api.get('<?= url('user/permissions') ?>&id='+id).then(function(res){
    if (res && res.user){
      document.getElementById('permUserId').value = res.user.id;
      document.getElementById('permUserName').textContent = res.user.name + ' ('+res.user.username+')';
      buildPermGrid(res.perms || {});
      Modal.open('permModal');
    } else {
      toast(res.message || '加载失败','error');
    }
  });
}

// 权限表单提交
document.getElementById('permForm').addEventListener('submit', function(e){
  e.preventDefault();
  var perms = collectPerms();
  document.getElementById('permsJson').value = JSON.stringify(perms);
  var fd = new FormData(this);
  Api.post(this.action, fd).then(function(res){
    if (res.success){ toast(res.message||'权限设置成功','success'); Modal.close('permModal'); }
    else toast(res.message||'操作失败','error');
  });
});

// 权限分配日志
function openPermLogs(id, name){
  Api.get('<?= url('user/permLogs') ?>&id='+id).then(function(res){
    if (!res || res.success === false){ toast((res && res.message) || '加载失败','error'); return; }
    var esc = function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); };
    var html = '';
    if (!res.logs || !res.logs.length){
      html = '<tr class="empty-row"><td colspan="3">暂无权限变更记录</td></tr>';
    } else {
      res.logs.forEach(function(lg){
        html += '<tr><td>'+esc(String(lg.created_at).substring(0,19))+'</td><td>'+esc(lg.operator_name)+'</td><td style="white-space:pre-line;text-align:left">'+esc(lg.changes)+'</td></tr>';
      });
    }
    document.getElementById('permLogBody').innerHTML = html;
    document.getElementById('permLogUserName').textContent = name || '';
    Modal.open('permLogModal');
  });
}

// 成员详情弹窗
function viewUserDetail(id){
  Api.get('<?= url('user/detail') ?>&id=' + id).then(function(r){
    if (!r || r.success === false || r.id === undefined){ toast((r && r.message) || '加载失败','error'); return; }
    var esc = function(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); };
    var row = function(label, val){
      return '<tr><td style="width:35%;color:#888;background:#fafafa">'+label+'</td><td>'+(val==null||val===''?'-':val)+'</td></tr>';
    };
    var html = '';
    html += row('ID', esc(r.id));
    html += row('用户名', '<strong>'+esc(r.username)+'</strong>');
    html += row('姓名', esc(r.name));
    html += row('角色', (parseInt(r.is_admin,10)===1) ? '<span class="badge badge-admin">管理员</span>' : '<span class="badge">成员</span>');
    html += row('电话', esc(r.phone));
    html += row('状态', (parseInt(r.status,10)===1) ? '<span class="badge badge-on">启用</span>' : '<span class="badge badge-off">禁用</span>');
    html += row('最近登录', r.last_login ? esc(String(r.last_login).substring(0,16)) : '-');
    html += row('创建时间', esc(String(r.created_at||'').substring(0,10)));
    document.getElementById('userDetailBody').innerHTML = html;
    document.getElementById('userDetailTitle').textContent = '成员详情 - ' + (r.name || '');
    Modal.open('userDetailModal');
  });
}

// 添加成员
document.getElementById('btnAddUser').addEventListener('click', function(){
  var form = document.getElementById('userForm');
  form.reset();
  form.action = '<?= url('user/store') ?>';
  document.getElementById('userModalTitle').textContent = '添加成员';
  document.getElementById('pwdReq').style.display = '';
  document.getElementById('pwdHint').textContent = '至少 6 位';
  form.querySelector('[name=password]').setAttribute('required','required');
  Modal.open('userModal');
});

// 编辑成员
function editUser(id){
  var form = document.getElementById('userForm');
  form.reset();
  Api.get('<?= url('user/edit') ?>&id='+id).then(function(res){
    if (!res || !res.id){ toast(res.message||'加载失败','error'); return; }
    form.querySelector('[name=username]').value = res.username||'';
    form.querySelector('[name=name]').value = res.name||'';
    form.querySelector('[name=phone]').value = res.phone||'';
    form.querySelector('[name=is_admin]').value = res.is_admin;
    form.querySelector('[name=status]').value = res.status;
    form.action = '<?= url('user/update') ?>&id='+id;
    document.getElementById('userModalTitle').textContent = '编辑成员';
    document.getElementById('pwdReq').style.display = 'none';
    document.getElementById('pwdHint').textContent = '留空表示不修改密码';
    form.querySelector('[name=password]').removeAttribute('required');
    Modal.open('userModal');
  });
}

// 删除成员
function delUser(id){
  confirmDelete({
    message: '确定要删除该成员吗？此操作不可恢复。',
    onOk: function(){
      Api.post('<?= url('user/destroy') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'删除成功','success'); setTimeout(function(){location.reload();},600); }
        else toast(res.message||'删除失败','error');
      });
    }
  });
}

// 成员表单提交
ajaxSubmit(document.getElementById('userForm'), {});

// 批量操作条（成员页无打印）：编辑（仅一条）/ 删除
bindBatch({
  deleteUrl: '<?= url('user/destroy') ?>',
  editFn: function(id){ editUser(id); }
});
</script>
