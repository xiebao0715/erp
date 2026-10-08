<?php
/**
 * 生产管理视图：生产任务 / 生产方案 双 Tab
 * 变量: $page(任务分页), $plans, $producible, $materials, $warehouses, 权限标志
 */
use Core\Auth;
use Core\Csrf;
$cu = Auth::instance();
?>
<div class="page-head">
  <h2>生产管理</h2>
  <div class="actions">
    <button class="btn <?= ($canCreate ?? false) ? 'btn-primary' : '' ?>" id="tabBtnTask" type="button">📋 生产任务</button>
    <button class="btn" id="tabBtnPlan" type="button">🧾 生产方案</button>
  </div>
</div>

<!-- ================= Tab 1：生产任务 ================= -->
<div id="paneTask">
  <div class="toolbar">
    <form method="get" action="<?= url('production/index') ?>" class="search-box search-filters">
      <input type="hidden" name="r" value="production/index">
      <input type="text" name="q" value="<?= e($page['search']) ?>" placeholder="搜索任务单号/商品编码/名称...">
      <select name="status" onchange="this.form.submit()" style="padding:7px 10px;border:1px solid var(--border);border-radius:4px;font-size:13px">
        <option value="">全部状态</option>
        <option value="0" <?= $page['status']==='0'?'selected':'' ?>>待生产</option>
        <option value="2" <?= $page['status']==='2'?'selected':'' ?>>生产中</option>
        <option value="1" <?= $page['status']==='1'?'selected':'' ?>>已完成</option>
        <option value="3" <?= $page['status']==='3'?'selected':'' ?>>已取消</option>
      </select>
      <button type="submit">查询</button>
    </form>
    <div class="toolbar-right">
      <?php if ($canCreate): ?><button class="btn btn-primary" id="btnAddTask">＋ 下达生产任务</button><?php endif; ?>
    </div>
  </div>

  <div class="table-wrap">
  <table class="data-table">
    <thead><tr>
      <th class="col-check"></th>
      <th>任务单号</th><th>生产商品</th><th>规格型号</th><th>生产数量</th><th>消耗材料</th>
      <th>领料仓库</th><th>入库仓库</th><th>目标产线</th><th>产线负责人</th><th>经办人</th><th>生产日期</th><th>材料成本</th><th>状态</th><th>领料出库单</th><th>成品入库单</th><th>操作</th>
    </tr></thead>
    <tbody>
    <?php if (empty($page['items'])): ?>
      <tr class="empty-row"><td colspan="17">暂无生产任务</td></tr>
    <?php else: foreach ($page['items'] as $r): ?>
      <tr>
        <td class="col-check"><input type="checkbox" class="row-check" value="<?= (int)$r['id'] ?>"></td>
        <td><strong><?= e($r['order_no']) ?></strong></td>
        <td><?= e($r['product_name']) ?></td>
        <td style="max-width:130px;color:#777"><?= e($r['product_spec'] ?: '-') ?></td>
        <td><span style="font-weight:bold"><?= number_format((float)$r['quantity'], 2) ?></span> <?= e($r['product_unit']) ?><?php if ((float)($r['cancel_done_qty'] ?? 0) > 0): ?><br><span style="font-size:12px;color:#999" title="含已取消 <?= number_format((float)$r['cancel_done_qty'], 2) ?>">原计划 <?= number_format((float)$r['quantity'] + (float)$r['cancel_done_qty'], 2) ?></span><?php endif; ?></td>
        <td style="max-width:170px" title="<?= e($r['material_names'] ?: '') ?>"><?= e($r['material_names'] ?: '-') ?></td>
        <td><?= e($r['warehouse_name']) ?></td>
        <td><?= e($r['inbound_warehouse_name'] ?: '-') ?></td>
        <td><?= e($r['line_name'] ?: '-') ?></td>
        <td><?= e($r['line_manager'] !== null && $r['line_manager'] !== '' ? $r['line_manager'] : '-') ?></td>
        <td><?= e($r['operator']) ?></td>
        <td><?= e($r['production_date']) ?></td>
        <td><span style="color:#27ae60;font-weight:bold">¥<?= number_format((float)$r['total_amount'], 2) ?></span></td>
        <td><?php
          $st = (int)$r['status'];
          echo $st === 1 ? '<span class="badge badge-on">已完成</span>'
             : ($st === 2 ? '<span class="badge badge-admin" title="材料已领料出库，成品待完工入库">生产中</span>'
                : ($st === 3 ? '<span class="badge" style="background:#f0f0f0;color:#666" title="数量已全部取消，任务自动完结">已取消</span>'
                             : '<span class="badge badge-warn">待生产</span>'));
        ?><?php if ((float)($r['cancel_pending_qty'] ?? 0) > 0): ?><br><span class="badge badge-warn" style="margin-top:3px" title="已登记取消，确认后材料按比例退回领料仓库，完工数量相应扣减">待取消 <?= number_format((float)$r['cancel_pending_qty'], 2) ?></span><?php endif; ?><?php if ((float)($r['cancel_done_qty'] ?? 0) > 0): ?><br><span class="badge" style="margin-top:3px;background:#f0f0f0;color:#666">已取消 <?= number_format((float)$r['cancel_done_qty'], 2) ?></span><?php endif; ?></td>
        <td>
          <?php if (!empty($r['outbound_no'])): ?>
            <a class="btn-link" href="<?= url('outbound/detail') ?>&id=<?= (int)$r['outbound_id'] ?>" target="_blank"><?= e($r['outbound_no']) ?></a>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td>
          <?php if (!empty($r['inbound_no'])): ?>
            <a class="btn-link" href="<?= url('inbound/detail') ?>&id=<?= (int)$r['inbound_id'] ?>" target="_blank"><?= e($r['inbound_no']) ?></a>
          <?php else: ?>-<?php endif; ?>
        </td>
        <td class="col-actions">
          <button class="btn-link" style="color:#2980b9" onclick="viewTask(<?= (int)$r['id'] ?>)">详情</button>
          <?php if ($canStart && $st === 0): ?><button class="btn-link" style="color:#8e44ad" onclick="startTask(<?= (int)$r['id'] ?>)">开始生产</button><?php endif; ?>
          <?php if ($canStart && $st === 2): ?><button class="btn-link" style="color:#27ae60;font-weight:bold" onclick="completeTask(<?= (int)$r['id'] ?>)">完成生产</button><?php endif; ?>
          <?php if ($canCancel && $st !== 3 && empty($r['cancel_pending_id']) && (float)$r['quantity'] > 0): ?><button class="btn-link" style="color:#e67e22" onclick="openCancel(<?= (int)$r['id'] ?>)">取消生产</button><?php endif; ?>
          <?php if ($canReturn && $st !== 3 && !empty($r['cancel_pending_id'])): ?><button class="btn-link" style="color:#e67e22;font-weight:bold" onclick="confirmCancel(<?= (int)$r['cancel_pending_id'] ?>)">确认取消</button><?php endif; ?>
          <?php if ($canEdit && empty($r['cancel_pending_id']) && (float)($r['cancel_done_qty'] ?? 0) <= 0): ?><button class="btn-link" onclick="editTask(<?= (int)$r['id'] ?>)">编辑</button><?php endif; ?>
          <?php if ($canDelete): ?><button class="btn-link danger" onclick="delTask(<?= (int)$r['id'] ?>)">删除</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <?php
  $baseUrl = url('production/index', array_filter(['q' => $page['search'], 'status' => $page['status'] ?? ''], fn($v) => $v !== '' && $v !== null));
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
</div>

<!-- ================= Tab 2：生产方案 ================= -->
<div id="panePlan" style="display:none">
  <div class="toolbar">
    <div class="toolbar-left" style="color:#888;font-size:13px">
      生产方案（BOM）定义每生产 1 件成品所需消耗的材料及数量；下达任务时系统自动按方案展开领料。
    </div>
    <div class="toolbar-right">
      <?php if ($canCreate): ?><button class="btn btn-primary" id="btnAddPlan">＋ 新增方案</button><?php endif; ?>
    </div>
  </div>

  <div class="table-wrap">
  <table class="data-table">
    <thead><tr>
      <th>生产商品编号</th><th>商品名称</th><th>规格型号</th><th style="text-align:right">生产数量</th><th>计量单位</th><th>下发人</th><th>备注</th><th>操作</th>
    </tr></thead>
    <tbody id="planBody">
    <?php if (empty($planGroups)): ?>
      <tr class="empty-row"><td colspan="8">暂无生产方案，请先新增</td></tr>
    <?php else: foreach ($planGroups as $g): ?>
      <tr data-id="<?= (int)$g['first_id'] ?>">
        <td class="mono"><?= e($g['product_code']) ?></td>
        <td><strong><?= e($g['product_name']) ?></strong></td>
        <td style="color:#777"><?= e($g['product_spec'] !== '' ? $g['product_spec'] : '-') ?></td>
        <td style="text-align:right"><strong><?php
          $gq = number_format((float)$g['total_qty'], 4);
          echo e(strpos($gq, '.') !== false ? rtrim(rtrim($gq, '0'), '.') : $gq);
        ?></strong><span style="color:#999;font-size:11px">（<?= (int)$g['material_count'] ?> 种材料）</span></td>
        <td><?= e($g['product_unit'] !== '' ? $g['product_unit'] : '-') ?></td>
        <td><?= e($g['operators'] ? implode('、', array_keys($g['operators'])) : '-') ?></td>
        <td style="max-width:180px"><?= e($g['remarks'] ? implode('；', array_keys($g['remarks'])) : '-') ?></td>
        <td class="col-actions">
          <button class="btn-link" style="color:#2980b9" onclick='viewPlan(<?= (int)$g['product_id'] ?>, <?= json_encode($g['product_name'], JSON_UNESCAPED_UNICODE) ?>)'>详情</button>
          <?php if ($canEdit): ?><button class="btn-link" onclick="editPlan(<?= (int)$g['first_id'] ?>)">编辑</button><?php endif; ?>
          <?php if ($canDelete): ?><button class="btn-link danger" onclick='delPlanProduct(<?= (int)$g['product_id'] ?>, <?= json_encode($g['product_name'], JSON_UNESCAPED_UNICODE) ?>)'>删除</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ================= 任务弹窗 ================= -->
<div class="modal-mask" id="taskModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3 id="taskModalTitle">下达生产任务</h3><button class="close" type="button">&times;</button></div>
    <form id="taskForm" method="post" action="<?= url('production/store') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div class="form-row">
          <div class="form-group" style="flex:2"><label>生产商品（仅可生产商品） <span class="req">*</span></label>
            <select name="product_id" id="taskProduct" data-search required>
              <option value="">请选择</option>
              <?php foreach ($producible as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= e($p['code']) ?> / <?= e($p['name']) ?><?= $p['spec'] ? ' ('.e($p['spec']).')' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>生产数量 <span class="req">*</span></label>
            <input type="number" step="0.01" min="0.01" name="quantity" id="taskQty" value="1" required>
          </div>
          <div class="form-group" style="flex:1.4"><label>目标产线 <span class="req">*</span></label>
            <select name="production_line_id" id="taskLine" required>
              <option value="">请选择</option>
              <?php foreach ($lines as $l): ?>
                <option value="<?= (int)$l['id'] ?>"><?= e($l['code']) ?> / <?= e($l['name']) ?><?= $l['manager'] !== '' ? '（'.e($l['manager']).'）' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>领料仓库（材料出库） <span class="req">*</span></label>
            <select name="warehouse_id" id="taskWarehouse" required>
              <option value="">请选择</option>
              <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>入库仓库（成品入库） <span class="req">*</span></label>
            <select name="inbound_warehouse_id" id="taskInboundWarehouse" required>
              <option value="">请选择</option>
              <?php foreach ($warehouses as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>经办人</label><input type="text" value="<?= e($cu->name()) ?>" disabled title="经办人自动记录为当前操作人"></div>
          <div class="form-group"><label>生产日期 <span class="req">*</span></label><input type="date" name="production_date" id="taskDate" value="<?= e(date('Y-m-d')) ?>" required></div>
          <div class="form-group"><label>状态</label>
            <select name="status" id="taskStatus">
              <option value="0">待生产（暂不扣减库存）</option>
              <option value="2">生产中（立即领料出库、扣减材料，成品暂不入库）</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>消耗方案（由生产方案自动展开；库存按领料仓库显示，开始生产或保存为生产中时扣减）</label>
          <div style="overflow-x:auto">
            <table class="items-table" id="bomTable">
              <thead><tr>
                <th style="width:22%">消耗商品</th><th style="width:10%">规格</th><th style="width:8%">单位</th>
                <th style="width:13%">单位用量</th><th style="width:13%">本次消耗</th><th style="width:14%">当前库存</th>
                <th style="width:12%">成本小计</th><th style="width:8%">备注</th>
              </tr></thead>
              <tbody id="bomBody">
                <tr><td colspan="8" style="text-align:center;color:#999;padding:14px">请先选择生产商品</td></tr>
              </tbody>
            </table>
          </div>
          <div class="totals">材料成本合计：<span class="total-amount" id="taskTotal">¥0.00</span></div>
        </div>

        <div class="form-group"><label>备注</label><textarea name="remark" id="taskRemark"></textarea></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="Modal.close('taskModal')">取消</button>
        <button type="submit" class="btn btn-primary">提交任务</button>
      </div>
    </form>
  </div>
</div>

<!-- ================= 方案弹窗（多材料明细表） ================= -->
<div class="modal-mask" id="planModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3 id="planModalTitle">新增生产方案</h3><button class="close" type="button">&times;</button></div>
    <form id="planForm" method="post" action="<?= url('production/planStore') ?>">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div class="form-group"><label>生产商品 <span class="req">*</span></label>
          <select name="product_id" id="planProduct" data-search required>
            <option value="">请选择</option>
            <?php foreach ($producible as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= e($p['code']) ?> / <?= e($p['name']) ?><?= $p['spec'] ? ' ('.e($p['spec']).')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>消耗材料明细（可添加多种材料；库存仅供参考，下达生产任务时才校验） <span class="req">*</span></label>
          <div style="overflow-x:auto">
            <table class="items-table" id="planItemsTable">
              <thead><tr>
                <th style="width:34%">消耗商品</th>
                <th style="width:13%">当前库存</th>
                <th style="width:15%">单位用量<span style="color:#999;font-weight:normal">（每生产1件成品）</span></th>
                <th style="width:9%">单位</th>
                <th style="width:20%">备注</th>
                <th style="width:9%">操作</th>
              </tr></thead>
              <tbody id="planItemsBody"></tbody>
            </table>
          </div>
          <button type="button" class="btn" id="btnAddPlanRow" style="margin-top:8px">＋ 添加一行</button>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="Modal.close('planModal')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
      </div>
    </form>
  </div>
</div>

<!-- ================= 方案详情弹窗（只读查看消耗商品） ================= -->
<div class="modal-mask" id="planDetailModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3>方案详情 - <span id="planDetailName"></span></h3><button class="close" type="button">&times;</button></div>
    <div class="modal-body">
      <div class="table-wrap">
        <table class="data-table">
          <thead><tr><th>消耗商品编码</th><th>消耗商品名称</th><th>规格型号</th><th>单位</th><th style="text-align:right">单位用量</th><th>备注</th></tr></thead>
          <tbody id="planDetailBody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" onclick="Modal.close('planDetailModal')">关闭</button></div>
  </div>
</div>

<!-- ================= 提交成功弹窗 ================= -->
<div class="modal-mask" id="resultModal">
  <div class="modal">
    <div class="modal-head"><h3>生产完成</h3></div>
    <div class="modal-body" style="text-align:center;padding:24px 20px">
      <div style="font-size:40px;margin-bottom:8px">✅</div>
      <div id="resultMsg" style="font-size:15px;margin-bottom:18px"></div>
      <div>
        <a class="btn btn-primary" id="resultViewBtn" href="#" target="_blank">查看领料出库单</a>
        <a class="btn" id="resultInboundBtn" href="#" target="_blank" style="display:none">查看成品入库单</a>
        <button type="button" class="btn" onclick="location.reload()">返回列表</button>
      </div>
    </div>
  </div>
</div>

<!-- ================= 取消生产弹窗 ================= -->
<div class="modal-mask" id="cancelModal">
  <div class="modal modal-lg">
    <div class="modal-head"><h3>取消生产登记</h3><button class="close" type="button">&times;</button></div>
    <form id="cancelForm" method="post">
      <?= Csrf::field() ?>
      <input type="hidden" name="id" value="">
      <div class="modal-body">
        <div id="cancelInfo" style="background:#fdf6ec;border:1px solid #f5dab1;border-radius:4px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#8a6d3b"></div>
        <div class="form-row">
          <div class="form-group"><label>取消数量 <span class="req">*</span></label>
            <input type="number" step="0.01" min="0.01" name="quantity" id="cancelQty" value="">
            <div id="cancelAfterHint" style="color:#e67e22;font-size:12px;margin-top:4px"></div>
          </div>
          <div class="form-group" style="flex:2"><label>备注</label><input type="text" name="remark" id="cancelRemark" placeholder="选填，如：客户撤单"></div>
        </div>
        <div class="form-group">
          <label>材料退回预览（按取消数量占当前数量的比例计算）</label>
          <div style="overflow-x:auto">
            <table class="items-table">
              <thead><tr>
                <th>消耗商品</th><th style="width:90px">规格</th><th style="width:70px">单位</th>
                <th style="width:120px">剩余消耗量</th><th style="width:150px" id="cancelBackHead">本次预计退回</th>
              </tr></thead>
              <tbody id="cancelBody"></tbody>
            </table>
          </div>
        </div>
        <div id="cancelHistory"></div>
        <div style="color:#999;font-size:12px">保存后生成「待取消」单，暂不动库存；在列表点「确认取消」后：生产数量同步扣减（减为 0 任务自动取消）；生产中/已完成任务会把消耗材料退回领料仓库，已完成任务还会从成品入库仓库扣减生产商品数量。</div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn" onclick="Modal.close('cancelModal')">取消</button>
        <button type="submit" class="btn btn-primary">登记取消</button>
      </div>
    </form>
  </div>
</div>

<script>
var PRODUCIBLE = <?= json_encode($producible, JSON_UNESCAPED_UNICODE) ?>;
var MATERIALS  = <?= json_encode($materials, JSON_UNESCAPED_UNICODE) ?>;
var PLANS      = <?= json_encode($plans, JSON_UNESCAPED_UNICODE) ?>;
var BOM_ITEMS  = [];          // 当前成品的方案行（planItems 返回）
var STOCK_CACHE = {};         // wid:mid → 库存
var planItemsLoaded = false;  // 弹窗中方案是否已成功加载（防止未加载方案时误提交）

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function num(v,d){ return (parseFloat(v)||0).toFixed(d==null?2:d); }
function fmt4(v){ var n = (parseFloat(v)||0).toFixed(4); return n.indexOf('.') >= 0 ? n.replace(/0+$/,'').replace(/\.$/,'') : n; }

// ---------------- Tab 切换 ----------------
function switchTab(which){
  var isPlan = which === 'plan';
  document.getElementById('paneTask').style.display = isPlan ? 'none' : '';
  document.getElementById('panePlan').style.display = isPlan ? '' : 'none';
  document.getElementById('tabBtnTask').className = 'btn' + (isPlan ? '' : ' btn-primary');
  document.getElementById('tabBtnPlan').className = 'btn' + (isPlan ? ' btn-primary' : '');
  if (isPlan) location.hash = 'plan'; else if (location.hash) history.replaceState(null,'',location.pathname + location.search);
}
document.getElementById('tabBtnTask').addEventListener('click', function(){ switchTab('task'); });
document.getElementById('tabBtnPlan').addEventListener('click', function(){ switchTab('plan'); });
if (location.hash === '#plan') switchTab('plan');

// ---------------- 任务：方案展开 ----------------
function taskQty(){ return parseFloat(document.getElementById('taskQty').value) || 0; }
function taskWarehouse(){ return document.getElementById('taskWarehouse').value; }

function refreshBomStockCell(td){
  var mid = td.getAttribute('data-mid');
  var wid = taskWarehouse();
  if (!mid){ td.textContent = '-'; return; }
  var key = wid + ':' + mid;
  function paint(q){
    td.setAttribute('data-stock', q);
    paintStockWarn(td);
  }
  if (STOCK_CACHE[key] !== undefined){ paint(STOCK_CACHE[key]); return; }
  if (!wid){ td.textContent = '选择仓库后查询'; td.removeAttribute('data-stock'); return; }
  td.textContent = '查询中...';
  Api.get('<?= url('inventory/stock') ?>&product_id=' + mid + '&warehouse_id=' + wid).then(function(res){
    var q = res && res.success === false ? 0 : parseFloat(res.quantity) || 0;
    STOCK_CACHE[key] = q; paint(q);
  }).catch(function(){ td.innerHTML = '<span style="color:#e67e22">?</span>'; });
}
function paintStockWarn(td){
  var need = parseFloat(td.getAttribute('data-need')) || 0;
  var stock = parseFloat(td.getAttribute('data-stock'));
  if (isNaN(stock)) return;
  td.innerHTML = stock < need
    ? '<span style="font-weight:bold;color:#e74c3c">' + num(stock) + '（不足）</span>'
    : '<span style="font-weight:bold;color:#333">' + num(stock) + '</span>';
}
function refreshAllStock(){
  document.querySelectorAll('#bomBody .b-stock').forEach(refreshBomStockCell);
}

function renderBom(items){
  BOM_ITEMS = items || [];
  var body = document.getElementById('bomBody');
  body.innerHTML = '';
  if (!BOM_ITEMS.length){
    body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#e67e22;padding:14px">该商品尚未配置生产方案，请先到「生产方案」Tab 添加消耗材料</td></tr>';
    planItemsLoaded = true;
    recalcBom();
    return;
  }
  BOM_ITEMS.forEach(function(it){
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td>'+esc(it.material_code)+' / '+esc(it.material_name)+'</td>'+
      '<td style="color:#777">'+esc(it.material_spec||'-')+'</td>'+
      '<td>'+esc(it.material_unit||'')+'</td>'+
      '<td class="b-bomqty">'+fmt4(it.bom_qty)+'</td>'+
      '<td class="b-need" style="font-weight:bold">0.00</td>'+
      '<td class="b-stock">-</td>'+
      '<td class="b-amount">¥0.00</td>'+
      '<td style="color:#888">'+esc(it.remark||'')+'</td>';
    var stockTd = tr.querySelector('.b-stock');
    stockTd.setAttribute('data-mid', it.material_id);
    body.appendChild(tr);
  });
  recalcBom();
  refreshAllStock();
  planItemsLoaded = true;
}

function recalcBom(){
  var q = taskQty();
  var total = 0;
  document.querySelectorAll('#bomBody tr').forEach(function(tr, idx){
    var needTd = tr.querySelector('.b-need');
    if (!needTd || !BOM_ITEMS[idx]) return;
    var it = BOM_ITEMS[idx];
    var need = Math.round((parseFloat(it.bom_qty)||0) * q * 100) / 100;
    var amount = need * (parseFloat(it.material_cost)||0);
    needTd.textContent = num(need);
    tr.querySelector('.b-amount').textContent = '¥' + num(amount);
    var stockTd = tr.querySelector('.b-stock');
    stockTd.setAttribute('data-need', need);
    paintStockWarn(stockTd);
    total += amount;
  });
  document.getElementById('taskTotal').textContent = '¥' + num(total);
}

function loadPlanItems(productId, afterLoad){
  var body = document.getElementById('bomBody');
  BOM_ITEMS = []; planItemsLoaded = false;
  if (!productId){
    body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#999;padding:14px">请先选择生产商品</td></tr>';
    recalcBom();
    return;
  }
  body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#999;padding:14px">方案加载中...</td></tr>';
  Api.get('<?= url('production/planItems') ?>&product_id=' + productId).then(function(res){
    renderBom(res && res.success === false ? [] : (res.items || []));
    if (afterLoad) afterLoad();
  }).catch(function(){
    body.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#e74c3c;padding:14px">方案加载失败，请重试</td></tr>';
  });
}

document.getElementById('taskProduct').addEventListener('change', function(){
  STOCK_CACHE = {};
  loadPlanItems(this.value);
});
document.getElementById('taskQty').addEventListener('input', recalcBom);
document.getElementById('taskWarehouse').addEventListener('change', refreshAllStock);

// ---------------- 任务：新增/编辑/删除/详情 ----------------
function resetTaskForm(){
  var f = document.getElementById('taskForm');
  f.reset();
  f.action = '<?= url('production/store') ?>';
  f.querySelector('[name=id]').value = '';
  document.getElementById('taskDate').value = '<?= e(date('Y-m-d')) ?>';
  document.getElementById('taskQty').value = '1';
  document.getElementById('taskStatus').value = '0';
  document.getElementById('taskInboundWarehouse').value = '';
  document.getElementById('bomBody').innerHTML = '<tr><td colspan="8" style="text-align:center;color:#999;padding:14px">请先选择生产商品</td></tr>';
  BOM_ITEMS = []; planItemsLoaded = false;
  document.getElementById('taskTotal').textContent = '¥0.00';
}
document.getElementById('btnAddTask').addEventListener('click', function(){
  resetTaskForm();
  document.getElementById('taskModalTitle').textContent = '下达生产任务';
  Modal.open('taskModal');
});

function editTask(id){
  Api.get('<?= url('production/edit') ?>&id='+id).then(function(res){
    if (!res || !res.order){ toast(res.message||'加载失败','error'); return; }
    resetTaskForm();
    var f = document.getElementById('taskForm');
    f.action = '<?= url('production/update') ?>&id='+id;
    f.querySelector('[name=id]').value = res.order.id;
    document.getElementById('taskProduct').value = res.order.product_id;
    document.getElementById('taskQty').value = res.order.quantity;
    document.getElementById('taskLine').value = res.order.production_line_id;
    document.getElementById('taskWarehouse').value = res.order.warehouse_id;
    document.getElementById('taskInboundWarehouse').value = res.order.inbound_warehouse_id || '';
    document.getElementById('taskDate').value = res.order.production_date;
    document.getElementById('taskStatus').value = (res.order.status === 0 || res.order.status === 2) ? res.order.status : 0;
    document.getElementById('taskRemark').value = res.order.remark || '';
    document.getElementById('taskModalTitle').textContent = '编辑生产任务';
    STOCK_CACHE = {};
    loadPlanItems(res.order.product_id, recalcBom);
    Modal.open('taskModal');
  }).catch(function(){ toast('网络异常','error'); });
}

function delTask(id){
  confirmDelete({
    title: '删除确认',
    confirmText: '确定删除',
    message: '确定要删除该生产任务吗？生产中任务将回滚领料出库（材料加回），已完成任务还会回滚成品入库（成品扣回）。',
    onOk: function(){
      Api.post('<?= url('production/destroy') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'删除成功','success'); setTimeout(function(){ location.reload(); }, 600); }
        else toast(res.message||'删除失败','error');
      });
    }
  });
}
function viewTask(id){ window.open('<?= url('production/detail') ?>&id='+id, '_blank'); }

// 结果弹窗：showInbound=false 时只显示领料出库单（开始生产/保存为生产中）
function showTaskResult(res, defaultMsg){
  document.getElementById('resultMsg').textContent = res.message || defaultMsg;
  document.getElementById('resultViewBtn').href = '<?= url('outbound/detail') ?>&id=' + res.outbound_id;
  var inBtn = document.getElementById('resultInboundBtn');
  if (res.inbound_id){
    inBtn.href = '<?= url('inbound/detail') ?>&id=' + res.inbound_id;
    inBtn.style.display = '';
  } else {
    inBtn.style.display = 'none';
  }
  Modal.open('resultModal');
}

// 待生产 → 生产中（立即领料出库：扣材料、生成领料出库单，成品不入库）
function startTask(id){
  confirmDelete({
    title: '开始生产确认',
    confirmText: '确定开始',
    okClass: 'btn-primary',
    message: '开始生产后系统将按方案从领料仓库扣减材料库存，并自动生成领料出库单；成品暂不入库，待「完成生产」时再入库。确定继续吗？',
    onOk: function(){
      Api.post('<?= url('production/start') ?>&id='+id).then(function(res){
        if (res.success){ showTaskResult(res, '已开始生产，材料已领料出库'); }
        else toast(res.message||'操作失败','error');
      }).catch(function(){ toast('网络异常，请重试','error'); });
    }
  });
}

// 生产中 → 已完成（仅成品入库）
function completeTask(id){
  confirmDelete({
    title: '完成生产确认',
    confirmText: '确定完成',
    okClass: 'btn-primary',
    message: '材料已在开始生产时领料出库。完成生产后，成品将按当前生产数量（已扣除确认取消量）入成品入库仓库（有待取消记录时需先确认取消），并自动生成生产入库单。确定继续吗？',
    onOk: function(){
      Api.post('<?= url('production/complete') ?>&id='+id).then(function(res){
        if (res.success){ showTaskResult(res, '生产完成，成品已入库'); }
        else toast(res.message||'操作失败','error');
      }).catch(function(){ toast('网络异常，请重试','error'); });
    }
  });
}

// ---------------- 取消生产 ----------------
var CANCEL_DATA = null;
function fmt2(v){ return (parseFloat(v)||0).toFixed(2).replace(/\.?0+$/,''); }
function openCancel(id){
  Api.get('<?= url('production/cancelItems') ?>&id='+id).then(function(res){
    if (!res || !res.success){ toast((res&&res.message)||'加载失败','error'); return; }
    CANCEL_DATA = res;
    var o = res.order;
    document.getElementById('cancelForm').action = '<?= url('production/cancelStore') ?>&id='+id;
    document.getElementById('cancelForm').reset();
    document.getElementById('cancelQty').max = res.remain_qty;
    var stTxt = {'0':'待生产（尚未领料，取消仅减少生产数量，不涉及材料退回）','1':'已完成（取消将退回消耗材料到领料仓库，并从成品入库仓库扣减生产商品）','2':'生产中（取消将把消耗材料按比例退回领料仓库）'};
    document.getElementById('cancelInfo').innerHTML =
      '任务单号：<b>'+esc(o.order_no)+'</b>　生产商品：<b>'+esc(o.product_name)+'</b>　当前数量：<b>'+fmt2(o.quantity)+'</b>'+
      '　领料仓库：<b>'+esc(o.warehouse_name||'-')+'</b>　已取消：<b>'+fmt2(res.done_qty)+'</b>　可取消：<b style="color:#e67e22">'+fmt2(res.remain_qty)+'</b>'+
      '<br>状态：'+(stTxt[String(o.status)]||'');
    var isPending = String(o.status) === '0';
    var html = '';
    (res.materials||[]).forEach(function(mt){
      html += '<tr data-qty="'+mt.quantity+'">'+
        '<td>'+esc(mt.material_code)+' / '+esc(mt.material_name)+'</td>'+
        '<td style="color:#777">'+esc(mt.material_spec||'-')+'</td>'+
        '<td>'+esc(mt.material_unit||'')+'</td>'+
        '<td class="cx-need">'+fmt2(mt.quantity)+'</td>'+
        '<td class="cx-back" style="font-weight:bold;color:#e67e22">0.00</td>'+
      '</tr>';
    });
    document.getElementById('cancelBody').innerHTML = html || '<tr><td colspan="5" style="text-align:center;color:#999">无材料快照</td></tr>';
    document.getElementById('cancelBackHead').textContent = isPending ? '本次退回（未领料，不回库）' : '本次预计退回';
    // 历史取消单
    var h = '';
    (res.cancels||[]).forEach(function(c){
      h += '<span class="badge '+(parseInt(c.status,10)===1?'badge-on':'badge-warn')+'" style="margin:2px 6px 2px 0">'
         + esc(c.order_no) + ' · ' + fmt2(c.quantity) + ' · ' + (parseInt(c.status,10)===1?'已取消':'待取消') + '</span>';
    });
    document.getElementById('cancelHistory').innerHTML = h ? '<div class="form-group"><label>历史取消单</label><div>'+h+'</div></div>' : '';
    recalcCancelPreview();
    Modal.open('cancelModal');
  }).catch(function(){ toast('网络异常','error'); });
}
function recalcCancelPreview(){
  if (!CANCEL_DATA) return;
  var qty = parseFloat(document.getElementById('cancelQty').value) || 0;
  var remain = parseFloat(CANCEL_DATA.remain_qty) || 0;
  var over = qty > remain + 0.00001;
  // 预览比例按可取消余量封顶，超量时退回预览不放大
  var effQty = over ? remain : qty;
  var ratio = CANCEL_DATA.order.quantity > 0 ? effQty / parseFloat(CANCEL_DATA.order.quantity) : 0;
  var isPending = String(CANCEL_DATA.order.status) === '0';
  document.querySelectorAll('#cancelBody tr').forEach(function(tr){
    var taskQty = parseFloat(tr.getAttribute('data-qty')) || 0;
    var back = isPending ? 0 : Math.round(taskQty * ratio * 100) / 100;
    tr.querySelector('.cx-back').textContent = fmt2(back);
  });
  var after = Math.max(0, (parseFloat(CANCEL_DATA.order.quantity)||0) - qty);
  var hint = document.getElementById('cancelAfterHint');
  var btn = document.querySelector('#cancelForm [type=submit]');
  if (hint) {
    if (over) {
      hint.style.color = '#e74c3c';
      hint.textContent = '取消数量不能大于剩余生产数量（可取消 ' + fmt2(remain) + '）';
    } else {
      hint.style.color = '#e67e22';
      hint.textContent = '取消后生产数量：' + fmt2(after) + (after <= 0 && qty > 0 ? '（数量减为 0，确认后任务将自动取消）' : '');
    }
  }
  if (btn) btn.disabled = over;
}
document.getElementById('cancelQty').addEventListener('input', recalcCancelPreview);
document.getElementById('cancelForm').addEventListener('submit', function(e){
  e.preventDefault();
  if (!CANCEL_DATA){ return; }
  var qty = parseFloat(document.getElementById('cancelQty').value) || 0;
  if (qty <= 0){ toast('取消数量必须大于 0','error'); return; }
  if (qty > parseFloat(CANCEL_DATA.remain_qty) + 0.00001){ toast('取消数量不能大于本单可取消数量 '+CANCEL_DATA.remain_qty,'error'); return; }
  var fd = new FormData();
  fd.append('quantity', qty);
  fd.append('remark', document.getElementById('cancelRemark').value);
  var btn = this.querySelector('[type=submit]'); var old = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '提交中...';
  Api.post(this.action, fd).then(function(res){
    btn.disabled = false; btn.innerHTML = old;
    if (res.success){
      Modal.close('cancelModal');
      toast(res.message||'已登记取消','success');
      setTimeout(function(){ location.reload(); }, 900);
    } else toast(res.message||'操作失败','error');
  }).catch(function(){ btn.disabled = false; btn.innerHTML = old; toast('网络异常','error'); });
});
<?php if ($canReturn): ?>
function confirmCancel(cancelId){
  confirmDelete({
    title: '确认取消',
    confirmText: '确认取消',
    okClass: 'btn-primary',
    message: '确认后该取消单变为「已取消」：生产数量同步扣减（减为 0 任务自动取消）；消耗材料按明细加回领料仓库；已完成任务还会扣减成品库存。确定继续吗？',
    onOk: function(){
      Api.post('<?= url('production/cancelComplete') ?>&id='+cancelId).then(function(res){
        if (res.success){ toast(res.message||'已确认取消','success'); setTimeout(function(){ location.reload(); }, 800); }
        else toast(res.message||'操作失败','error');
      }).catch(function(){ toast('网络异常','error'); });
    }
  });
}
<?php endif; ?>

// 表单提交前：保存为「生产中」时做材料库存前端预检（后端会再次强校验）
function checkBomStock(){
  var lack = [];
  document.querySelectorAll('#bomBody tr').forEach(function(tr, idx){
    var td = tr.querySelector('.b-stock');
    if (!td || !BOM_ITEMS[idx]) return;
    var stock = parseFloat(td.getAttribute('data-stock'));
    var need = parseFloat(td.getAttribute('data-need')) || 0;
    if (isNaN(stock)){
      lack.push((BOM_ITEMS[idx].material_name||'材料')+'（库存未查询）');
    } else if (stock < need){
      lack.push((BOM_ITEMS[idx].material_name||'材料')+'（需 '+need+'，库存 '+stock+'）');
    }
  });
  return lack;
}

document.getElementById('taskForm').addEventListener('submit', function(e){
  e.preventDefault();
  var pid = document.getElementById('taskProduct').value;
  var qty = parseFloat(document.getElementById('taskQty').value) || 0;
  var wid = document.getElementById('taskWarehouse').value;
  var iwid = document.getElementById('taskInboundWarehouse').value;
  var lid = document.getElementById('taskLine').value;
  if (!pid){ toast('请选择生产商品','error'); return; }
  if (qty <= 0){ toast('生产数量必须大于 0','error'); return; }
  if (!lid){ toast('请选择目标产线','error'); return; }
  if (!wid){ toast('请选择领料仓库','error'); return; }
  if (!iwid){ toast('请选择成品入库仓库','error'); return; }
  if (!planItemsLoaded){ toast('消耗方案加载中，请稍候','error'); return; }
  if (!BOM_ITEMS.length){ toast('该商品未配置生产方案，无法下达任务','error'); return; }
  // 保存为生产中：立即领料出库，先做前端库存预检
  if (document.getElementById('taskStatus').value === '2'){
    var lack = checkBomStock();
    if (lack.length){ toast('材料库存不足，无法开始生产：'+lack.join('；'),'error'); return; }
  }

  var btn = this.querySelector('[type=submit]'); var old = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '提交中...';
  Api.post(this.action, new FormData(this)).then(function(res){
    btn.disabled = false; btn.innerHTML = old;
    if (res.success){
      Modal.close('taskModal');
      if (res.outbound_id){
        showTaskResult(res, '保存成功，材料已领料出库');
      } else {
        toast(res.message||'保存成功','success');
        setTimeout(function(){ location.reload(); }, 700);
      }
    } else {
      toast(res.message||'操作失败，请重试','error');
    }
  }).catch(function(){
    btn.disabled = false; btn.innerHTML = old;
    toast('网络异常，请重试','error');
  });
});

// ---------------- 方案：多材料明细表 ----------------
function planMaterialOptions(){
  var html = '<option value="">请选择商品</option>';
  MATERIALS.forEach(function(m){
    var stock = parseFloat(m.stock) || 0;
    html += '<option value="'+m.id+'" data-unit="'+esc(m.unit||'')+'" data-stock="'+stock+'">'
          + esc(m.code)+' / '+esc(m.name)+(m.spec ? ' ('+esc(m.spec)+')' : '')+'</option>';
  });
  return html;
}

// 添加一行；selectedId 指定已选材料，extraOpt 为已停用材料的补充选项
function addPlanRow(selectedId, qty, remark, extraOpt){
  var body = document.getElementById('planItemsBody');
  var tr = document.createElement('tr');
  tr.className = 'plan-row';
  tr.innerHTML =
      '<td><select class="p-material" name="material_id[]" data-search>' + planMaterialOptions() + (extraOpt || '') + '</select></td>'
    + '<td class="p-stock" style="text-align:center">-</td>'
    + '<td><input type="number" class="p-qty" name="quantity[]" step="0.0001" min="0.0001" value="'+(qty != null ? qty : 1)+'" style="width:100%" title="每生产 1 件成品的消耗量，不能超过当前库存"></td>'
    + '<td><input type="text" class="p-unit" disabled value="" style="width:100%"></td>'
    + '<td><input type="text" class="p-remark" name="remark[]" maxlength="255" value="'+esc(remark||'')+'" style="width:100%"></td>'
    + '<td style="text-align:center"><button type="button" class="btn-link danger p-del">✕ 删除</button></td>';
  body.appendChild(tr);
  var sel = tr.querySelector('.p-material');
  if (selectedId) sel.value = selectedId;
  sel.addEventListener('change', function(){
    // 消耗商品不能重复选择：与其他行重复则清空本行
    var v = sel.value;
    if (v){
      var dup = false;
      document.querySelectorAll('#planItemsBody .p-material').forEach(function(s){ if (s !== sel && s.value === v) dup = true; });
      if (dup){
        toast('该消耗商品已选择，不能重复添加', 'error');
        sel.value = '';
        sel.dispatchEvent(new Event('change', {bubbles:true}));
        return;
      }
    }
    refreshPlanRow(tr);
  });
  tr.querySelector('.p-del').addEventListener('click', function(){
    tr.remove();
    markPlanDuplicates();
  });
  refreshPlanRow(tr);
}

// 选材料后：带出单位、显示参考库存（方案不校验库存）
function refreshPlanRow(tr){
  var sel = tr.querySelector('.p-material');
  var opt = sel.options[sel.selectedIndex];
  var unitInp = tr.querySelector('.p-unit');
  var stockTd = tr.querySelector('.p-stock');
  if (!opt || !sel.value){
    unitInp.value = '';
    stockTd.innerHTML = '-';
    stockTd.removeAttribute('data-stock');
  } else {
    unitInp.value = opt.getAttribute('data-unit') || '';
    var sv = opt.getAttribute('data-stock');
    if (sv === null){
      // 已停用材料（仅历史方案回显时存在）
      stockTd.removeAttribute('data-stock');
      stockTd.innerHTML = '<span style="color:#e67e22">已停用</span>';
    } else {
      stockTd.setAttribute('data-stock', sv);
      stockTd.innerHTML = '<span style="color:#555">'+num(parseFloat(sv)||0)+'</span>';
    }
  }
  markPlanDuplicates();
}

function markPlanDuplicates(){
  var counts = {};
  document.querySelectorAll('#planItemsBody .p-material').forEach(function(s){
    if (s.value) counts[s.value] = (counts[s.value] || 0) + 1;
  });
  document.querySelectorAll('#planItemsBody .plan-row').forEach(function(tr){
    var s = tr.querySelector('.p-material');
    s.style.borderColor = (s.value && counts[s.value] > 1) ? '#e74c3c' : '';
  });
}

// 选成品后：若已有方案则带出全部材料行，否则给一空行
function refillPlanRows(){
  var body = document.getElementById('planItemsBody');
  body.innerHTML = '';
  var pid = document.getElementById('planProduct').value;
  var rows = PLANS.filter(function(b){ return String(b.product_id) === String(pid); });
  if (!rows.length){
    addPlanRow();
    return;
  }
  rows.forEach(function(b){
    var known = MATERIALS.some(function(m){ return String(m.id) === String(b.material_id); });
    if (known){
      addPlanRow(b.material_id, b.quantity, b.remark);
    } else {
      // 材料已停用：补一个选项用于回显（保存时后端会要求先启用或移除）
      var extra = '<option value="'+b.material_id+'" data-unit="'+esc(b.material_unit||'')+'" data-disabled="1">'
                + esc(b.material_code)+' / '+esc(b.material_name)+'（已停用）</option>';
      addPlanRow(b.material_id, b.quantity, b.remark, extra);
    }
  });
}

function resetPlanForm(){
  var f = document.getElementById('planForm');
  f.reset();
  f.action = '<?= url('production/planStore') ?>';
  f.querySelector('[name=id]').value = '';
  document.getElementById('planProduct').disabled = false;
  refillPlanRows();
}
document.getElementById('planProduct').addEventListener('change', refillPlanRows);
document.getElementById('btnAddPlanRow').addEventListener('click', function(){ addPlanRow(); });
document.getElementById('btnAddPlan').addEventListener('click', function(){
  resetPlanForm();
  document.getElementById('planModalTitle').textContent = '新增生产方案';
  Modal.open('planModal');
});
// 方案详情：只读查看该成品的全部消耗商品
function viewPlan(productId, productName){
  var rows = PLANS.filter(function(b){ return String(b.product_id) === String(productId); });
  var html = '';
  rows.forEach(function(b){
    var q = parseFloat(b.quantity) || 0;
    var qs = q.toFixed(4).replace(/\.?0+$/, '');
    html += '<tr>'
      + '<td>' + esc(b.material_code) + '</td>'
      + '<td>' + esc(b.material_name) + '</td>'
      + '<td style="color:#777">' + esc(b.material_spec || '-') + '</td>'
      + '<td>' + esc(b.unit || b.material_unit || '') + '</td>'
      + '<td style="text-align:right;font-weight:bold">' + esc(qs) + '</td>'
      + '<td>' + esc(b.remark || '-') + '</td>'
      + '</tr>';
  });
  document.getElementById('planDetailBody').innerHTML = html || '<tr class="empty-row"><td colspan="6">暂无消耗商品</td></tr>';
  document.getElementById('planDetailName').textContent = productName || '';
  Modal.open('planDetailModal');
}
function editPlan(id){
  var row = PLANS.filter(function(b){ return String(b.id) === String(id); })[0];
  if (!row){ toast('方案不存在','error'); return; }
  resetPlanForm();
  var f = document.getElementById('planForm');
  f.action = '<?= url('production/planUpdate') ?>&id='+id;
  f.querySelector('[name=id]').value = id;
  ensureOption('planProduct', row.product_id, row.product_code + ' / ' + row.product_name);
  var psel = document.getElementById('planProduct');
  psel.value = row.product_id;
  psel.disabled = true;
  refillPlanRows();
  document.getElementById('planModalTitle').textContent = '编辑生产方案（' + row.product_name + ' 的全部材料）';
  Modal.open('planModal');
}
// 停用商品不在下拉中时补一个选项，保证历史方案可回显
function ensureOption(selectId, value, label){
  var sel = document.getElementById(selectId);
  var hit = Array.prototype.some.call(sel.options, function(o){ return o.value === String(value); });
  if (!hit){
    var opt = document.createElement('option');
    opt.value = value; opt.textContent = label + '（已停用）';
    sel.appendChild(opt);
  }
}
function delPlan(id){
  confirmDelete({
    message: '确定删除该方案行吗？已下达的生产任务不受影响（任务保存了消耗快照）。',
    onOk: function(){
      Api.post('<?= url('production/planDestroy') ?>&id='+id).then(function(res){
        if (res.success){ toast(res.message||'删除成功','success'); setTimeout(function(){ location.reload(); }, 600); }
        else toast(res.message||'删除失败','error');
      });
    }
  });
}
// 按成品删除整套方案
function delPlanProduct(pid, name){
  confirmDelete({
    message: '确定删除「' + name + '」的整套生产方案吗？其全部消耗商品将被移除；已下达的生产任务不受影响（任务保存了消耗快照）。',
    onOk: function(){
      Api.post('<?= url('production/planDestroy') ?>&pid='+pid).then(function(res){
        if (res.success){ toast(res.message||'删除成功','success'); setTimeout(function(){ location.reload(); }, 600); }
        else toast(res.message||'删除失败','error');
      });
    }
  });
}
document.getElementById('planForm').addEventListener('submit', function(e){
  e.preventDefault();
  if (!document.getElementById('planProduct').value){ toast('请选择生产商品','error'); return; }
  var rows = document.querySelectorAll('#planItemsBody .plan-row');
  if (!rows.length){ toast('请至少添加一种消耗商品','error'); return; }
  var valid = 0, seen = {};
  for (var i = 0; i < rows.length; i++){
    var tr = rows[i];
    var sel = tr.querySelector('.p-material');
    var opt = sel.options[sel.selectedIndex];
    var mid = sel.value;
    var qty = parseFloat(tr.querySelector('.p-qty').value) || 0;
    if (!mid){
      // 空白行：若所有行都空白则报错，否则提交时后端会自动忽略
      continue;
    }
    if (opt && opt.getAttribute('data-disabled') === '1'){
      toast('第 '+(i+1)+' 行材料已停用，请移除该行或先在商品管理中启用','error'); return;
    }
    if (qty <= 0){ toast('第 '+(i+1)+' 行单位用量必须大于 0','error'); return; }
    if (seen[mid]){ toast('消耗商品重复，请合并为一行（第 '+(i+1)+' 行）','error'); return; }
    seen[mid] = true;
    valid++;
  }
  if (!valid){ toast('请至少添加一种消耗商品','error'); return; }

  var btn = this.querySelector('[type=submit]'); var old = btn.innerHTML;
  btn.disabled = true; btn.innerHTML = '保存中...';
  // 编辑时成品下拉被禁用（禁用控件不参与提交），序列化前临时启用
  var psel0 = document.getElementById('planProduct');
  psel0.disabled = false;
  var fd = new FormData(this);
  Api.post(this.action, fd).then(function(res){
    btn.disabled = false; btn.innerHTML = old;
    if (res.success){ toast(res.message||'保存成功','success'); Modal.close('planModal'); setTimeout(function(){ location.reload(); }, 600); }
    else toast(res.message||'保存失败','error');
  }).catch(function(){
    btn.disabled = false; btn.innerHTML = old;
    toast('网络异常，请重试','error');
  });
});

// ---------------- 批量打印 / 删除（任务表） ----------------
function currentQuery(){
  var q = '<?= urlencode((string)$page['search']) ?>';
  var st = '<?= urlencode((string)$page['status']) ?>';
  var parts = [];
  if (q) parts.push('q=' + q);
  if (st) parts.push('status=' + st);
  return parts.length ? '&' + parts.join('&') : '';
}
bindBatch({
  <?php if ($canPrint): ?>printUrl: function(){ return '<?= url('production/print') ?>' + currentQuery(); },<?php endif; ?>
  deleteUrl: '<?= $canDelete ? url('production/destroy') : '' ?>'<?php if ($canEdit): ?>,
  editFn: function(id){ editTask(id); }<?php endif; ?>
});
</script>
