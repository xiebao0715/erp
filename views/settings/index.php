<?php
use Core\Csrf;
?>
<div class="page-head">
  <h2>系统设置</h2>
</div>

<div class="alert alert-info" style="margin-bottom:16px">修改以下参数后点击「保存设置」即可生效。库存预警阈值：0 表示按商品最低库存预警；填入正数表示按全局阈值（库存 &lt; 该值 即预警）。</div>

<div class="content-card" style="margin-bottom:16px">
  <h3 style="font-size:15px;margin-bottom:12px">登录页设置</h3>
  <div class="loginbg-row">
    <div class="loginbg-preview" id="bgPreview">
      <?php if ($loginBg !== ''): ?>
        <img src="<?= e(asset($loginBg)) ?>" alt="登录背景预览">
      <?php else: ?>
        <span class="loginbg-empty">未设置背景（使用默认深色渐变）</span>
      <?php endif; ?>
    </div>
    <div class="loginbg-ops">
      <label class="btn btn-primary" style="cursor:pointer">
        📷 上传背景图片
        <input type="file" id="bgFile" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
      </label>
      <label class="loginbg-switch"><input type="checkbox" id="bgEnabled" <?= $loginBgEnabled ? 'checked' : '' ?>> 开启自定义背景</label>
      <button type="button" class="btn btn-danger" id="bgClearBtn">清除背景（不设置）</button>
    </div>
  </div>
  <div class="form-hint">更换背景时自动删除旧背景图片；关闭开关不会删除图片，重新开启后直接使用。支持 JPG / PNG / GIF / WEBP，不超过 8MB。公司名称在下方「公司名称」设置项中修改，保存后显示在登录按钮下方。</div>
</div>

<form id="settingsForm" method="post" action="<?= url('settings/update') ?>">
  <?= Csrf::field() ?>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr>
        <th style="width:25%">设置项</th>
        <th style="width:25%">键</th>
        <th>值</th>
      </tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr class="empty-row"><td colspan="3">暂无设置项</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= e($r['label']) ?></td>
          <td><code><?= e($r['key']) ?></code></td>
          <td><input type="text" name="settings[<?= e($r['key']) ?>]" value="<?= e($r['value']) ?>" style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:4px;font-size:13px"></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div style="margin-top:16px;text-align:right">
    <button type="submit" class="btn btn-primary">💾 保存设置</button>
  </div>
</form>

<script>
document.getElementById('settingsForm').addEventListener('submit', function(e){
  e.preventDefault();
  var btn = this.querySelector('[type=submit]'); var old = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '保存中...';
  Api.post(this.action, new FormData(this)).then(function(res){
    btn.disabled = false; btn.innerHTML = old;
    if (res.success){ toast(res.message || '保存成功','success'); setTimeout(function(){ location.reload(); }, 800); }
    else toast(res.message || '保存失败','error');
  }).catch(function(){ btn.disabled = false; btn.innerHTML = old; toast('网络异常','error'); });
});

/* ---------- 登录背景设置 ---------- */
var bgEnabledEl = document.getElementById('bgEnabled');
function setBgPreview(url){
  var p = document.getElementById('bgPreview');
  p.innerHTML = url ? '<img src="'+url+'" alt="登录背景预览">' : '<span class="loginbg-empty">未设置背景（使用默认深色渐变）</span>';
}
document.getElementById('bgFile').addEventListener('change', function(){
  var f = this.files && this.files[0];
  this.value = '';
  if (!f) return;
  var fd = new FormData();
  fd.append('bg_file', f);
  Api.post('<?= url('settings/uploadLoginBg') ?>', fd).then(function(res){
    if (res.success){ toast(res.message || '上传成功','success'); setBgPreview(res.url || ''); }
    else toast(res.message || '上传失败','error');
  }).catch(function(){ toast('网络异常','error'); });
});
bgEnabledEl.addEventListener('change', function(){
  var checked = bgEnabledEl.checked;
  var fd = new FormData();
  fd.append('enabled', checked ? '1' : '0');
  Api.post('<?= url('settings/toggleLoginBg') ?>', fd).then(function(res){
    toast(res.message || '操作成功', res.success ? 'success' : 'error');
    if (!res.success) bgEnabledEl.checked = !checked;
  }).catch(function(){ toast('网络异常','error'); bgEnabledEl.checked = !checked; });
});
document.getElementById('bgClearBtn').addEventListener('click', function(){
  if (!confirm('确定清除登录背景图片吗？清除后恢复默认背景。')) return;
  Api.post('<?= url('settings/clearLoginBg') ?>', new FormData()).then(function(res){
    if (res.success){ toast(res.message || '已清除','success'); setBgPreview(''); bgEnabledEl.checked = false; }
    else toast(res.message || '操作失败','error');
  }).catch(function(){ toast('网络异常','error'); });
});
</script>
