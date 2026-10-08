<?php /** 权限不足提示（嵌入主布局） */ ?>
<div style="text-align:center;padding:50px 20px">
  <div style="font-size:64px;color:#e74c3c">🔒</div>
  <h2 style="color:#e74c3c;margin:14px 0 8px">权限不足</h2>
  <p style="color:#888">您没有访问该功能的权限，请联系管理员开通。</p>
  <a href="<?= url('dashboard/index') ?>" class="btn btn-primary" style="margin-top:18px">返回首页</a>
</div>
