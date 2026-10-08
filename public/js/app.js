/* ========== 工厂仓库 ERP 管理系统 前端交互 ========== */
(function () {
  'use strict';

  // CSRF 令牌（取 meta 标签）
  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  // 统一 fetch 封装
  function req(url, method, data) {
    method = method || 'GET';
    var opts = { method: method, credentials: 'same-origin', headers: {} };
    if (method !== 'GET') {
      opts.headers['X-CSRF-TOKEN'] = csrfToken();
      opts.headers['X-Requested-With'] = 'XMLHttpRequest';
      if (data instanceof FormData) {
        opts.body = data;
      } else if (typeof data === 'string') {
        // 已编码好的请求体原样发送
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
        opts.body = data;
      } else {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
        opts.body = data ? serialize(data) : '';
      }
    }
    return fetch(url, opts).then(function (r) {
      if (r.status === 401) { location.href = '?r=auth/login'; return Promise.reject(); }
      return r.json();
    });
  }

  function serialize(obj) {
    var s = [];
    for (var k in obj) { if (Object.prototype.hasOwnProperty.call(obj, k)) s.push(encodeURIComponent(k) + '=' + encodeURIComponent(obj[k])); }
    return s.join('&');
  }

  // 序列化表单为对象
  function formToObj(form) {
    var fd = new FormData(form);
    var obj = {};
    fd.forEach(function (v, k) {
      if (obj[k] !== undefined) {
        if (!Array.isArray(obj[k])) obj[k] = [obj[k]];
        obj[k].push(v);
      } else {
        obj[k] = v;
      }
    });
    return obj;
  }

  // Toast 提示
  function toast(msg, type) {
    type = type || 'success';
    var box = document.getElementById('toastBox');
    if (!box) {
      box = document.createElement('div');
      box.id = 'toastBox';
      box.className = 'toast';
      document.body.appendChild(box);
    }
    var item = document.createElement('div');
    item.className = 'toast-item ' + type;
    item.innerHTML = '<span>' + escapeHtml(msg) + '</span>';
    box.appendChild(item);
    setTimeout(function () {
      item.style.opacity = '0';
      item.style.transition = 'opacity .3s';
      setTimeout(function () { if (item.parentNode) item.parentNode.removeChild(item); }, 300);
    }, 2500);
  }
  window.toast = toast;

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- 弹窗管理 ---------- */
  var Modal = {
    open: function (id) {
      var el = document.getElementById(id);
      if (el) {
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
        // 弹窗打开后为声明了 data-search 的下拉框（商品/供应商/客户）渲染可搜索选择器
        el.querySelectorAll('select[data-search]').forEach(enhanceSelect);
      }
    },
    close: function (id) {
      var el = document.getElementById(id);
      if (el) { el.classList.remove('show'); document.body.style.overflow = ''; }
    },
    closeAll: function () {
      document.querySelectorAll('.modal-mask.show').forEach(function (el) { el.classList.remove('show'); });
      document.body.style.overflow = '';
    }
  };
  window.Modal = Modal;

  /* ---------- 可搜索下拉选择器（仅商品/供应商/客户，select 上加 data-search 启用） ----------
     原 select 保留用于表单提交与取值；点击显示栏展开面板，面板顶部为搜索输入框 */
  function enhanceSelect(sel) {
    if (!sel || sel.dataset.searchOk === '1') return;
    sel.dataset.searchOk = '1';
    // 不用 display:none：保留原生 required 校验可聚焦；视觉上由显示栏覆盖
    sel.style.cssText = 'position:absolute;left:0;top:0;width:100%;height:32px;opacity:0;pointer-events:none;z-index:-1;';

    var wrap = document.createElement('div');
    wrap.className = 'ss-wrap';
    wrap.style.cssText = 'position:relative;width:100%;';
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);

    var disp = document.createElement('div');
    disp.className = 'ss-display';
    disp.style.cssText = 'box-sizing:border-box;width:100%;border:1px solid #dcdfe6;border-radius:4px;height:32px;line-height:30px;padding:0 24px 0 10px;font-size:13px;background:#fff;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;position:relative;';
    disp.innerHTML = '<span class="ss-t"></span><span style="position:absolute;right:8px;top:0;color:#909399;font-size:12px;">▾</span>';
    wrap.appendChild(disp);

    var panel = document.createElement('div');
    panel.className = 'ss-panel';
    panel.style.cssText = 'display:none;position:absolute;z-index:1200;top:100%;left:0;right:0;background:#fff;border:1px solid #dcdfe6;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.15);margin-top:2px;flex-direction:column;';
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.placeholder = '搜索';
    inp.style.cssText = 'box-sizing:border-box;width:calc(100% - 12px);margin:6px;padding:5px 8px;font-size:13px;border:1px solid #dcdfe6;border-radius:4px;';
    var list = document.createElement('div');
    list.style.cssText = 'overflow-y:auto;max-height:200px;';
    panel.appendChild(inp);
    panel.appendChild(list);
    wrap.appendChild(panel);

    var t = disp.querySelector('.ss-t');
    function refreshDisp() {
      var o = sel.options[sel.selectedIndex];
      var has = o && o.value !== '';
      t.textContent = has ? o.text : (sel.options[0] ? sel.options[0].text : '请选择');
      t.style.color = has ? '#303133' : '#909399';
      disp.style.background = sel.disabled ? '#f5f7fa' : '#fff';
      disp.style.cursor = sel.disabled ? 'not-allowed' : 'pointer';
      disp._v = sel.value;
      disp._d = sel.disabled;
    }
    function renderList(kw) {
      list.innerHTML = '';
      var hit = 0;
      Array.prototype.forEach.call(sel.options, function (o) {
        if (o.value === '') return;
        if (kw && o.text.toLowerCase().indexOf(kw) < 0) return;
        hit++;
        var d = document.createElement('div');
        d.textContent = o.text;
        var on = o.value === sel.value;
        d.style.cssText = 'padding:6px 10px;font-size:13px;cursor:pointer;' + (on ? 'background:#ecf5ff;color:#409eff;' : '');
        d.addEventListener('mouseenter', function () { if (o.value !== sel.value) d.style.background = '#f5f7fa'; });
        d.addEventListener('mouseleave', function () { if (o.value !== sel.value) d.style.background = ''; });
        d.addEventListener('click', function () {
          sel.value = o.value;
          sel.dispatchEvent(new Event('change', { bubbles: true }));
          refreshDisp();
          close();
        });
        list.appendChild(d);
      });
      if (!hit) {
        var em = document.createElement('div');
        em.textContent = '无匹配项';
        em.style.cssText = 'padding:8px 10px;color:#909399;font-size:12px;';
        list.appendChild(em);
      }
    }
    function open() { refreshDisp(); panel.style.display = 'flex'; inp.value = ''; renderList(''); inp.focus(); }
    function close() { panel.style.display = 'none'; }
    disp.addEventListener('click', function (e) { e.stopPropagation(); if (sel.disabled) return; panel.style.display === 'none' ? open() : close(); });
    inp.addEventListener('click', function (e) { e.stopPropagation(); });
    inp.addEventListener('input', function () { renderList(inp.value.trim().toLowerCase()); });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) close(); });
    sel.addEventListener('change', refreshDisp);
    refreshDisp();
    // 编辑回填 / 表单重置 / 禁用切换等程序赋值后同步显示
    var timer = setInterval(function () {
      if (!document.contains(wrap)) { clearInterval(timer); return; }
      if (disp._v !== sel.value || disp._d !== sel.disabled) refreshDisp();
    }, 300);
  }
  window.enhanceSelect = enhanceSelect;
  // 动态新增的可搜索下拉框（如明细行商品选择）自动渲染
  var selObserver = new MutationObserver(function (muts) {
    muts.forEach(function (m) {
      m.addedNodes.forEach(function (n) {
        if (!n || n.nodeType !== 1) return;
        if (n.tagName === 'SELECT' && n.dataset.search !== undefined) enhanceSelect(n);
        else if (n.querySelectorAll) n.querySelectorAll('select[data-search]').forEach(enhanceSelect);
      });
    });
  });
  selObserver.observe(document.documentElement, { childList: true, subtree: true });

  // 点击弹窗关闭按钮（×）；点击遮罩空白区域不关闭，防止误触丢失已填内容
  document.addEventListener('click', function (e) {
    if (e.target.classList && e.target.contains && e.target.classList.contains('close')) {
      var mask = e.target.closest('.modal-mask');
      if (mask) Modal.close(mask.id);
    }
  });
  // ESC 关闭
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') Modal.closeAll();
  });

  /* ---------- 通用确认框（默认用于删除，也可自定义标题/按钮文案/样式，用于状态推进等） ---------- */
  function confirmDelete(opts) {
    var box = document.getElementById('confirmBox');
    if (!box) {
      box = document.createElement('div');
      box.id = 'confirmBox';
      box.className = 'modal-mask';
      box.innerHTML =
        '<div class="modal modal-sm">' +
        '<div class="modal-head"><h3 id="confirmTitle">删除确认</h3><button class="close" type="button">&times;</button></div>' +
        '<div class="modal-body"><p style="font-size:14px;color:#555" id="confirmMsg">确定要删除吗？此操作不可恢复。</p></div>' +
        '<div class="modal-foot"><button class="btn" type="button" id="confirmCancel">取消</button><button class="btn btn-danger" type="button" id="confirmOk">确定删除</button></div>' +
        '</div>';
      document.body.appendChild(box);
      box.querySelector('.close').onclick = function () { Modal.close('confirmBox'); };
      box.querySelector('#confirmCancel').onclick = function () { Modal.close('confirmBox'); };
    }
    box.querySelector('#confirmTitle').textContent = opts.title || '删除确认';
    box.querySelector('#confirmMsg').textContent = opts.message || '确定要删除吗？此操作不可恢复。';
    var okBtn = box.querySelector('#confirmOk');
    okBtn.textContent = opts.confirmText || '确定删除';
    okBtn.className = 'btn ' + (opts.okClass || 'btn-danger');
    Modal.open('confirmBox');
    okBtn.onclick = function () {
      Modal.close('confirmBox');
      if (typeof opts.onOk === 'function') opts.onOk();
    };
  }
  window.confirmDelete = confirmDelete;

  /* ---------- 通用 AJAX 表单 ---------- */
  function ajaxSubmit(form, opts) {
    opts = opts || {};
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var url = form.getAttribute('action') || opts.url;
      var data = formToObj(form);
      var submitBtn = form.querySelector('[type="submit"]');
      var oldText = submitBtn ? submitBtn.innerHTML : '';
      if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '提交中...'; }
      req(url, 'POST', data).then(function (res) {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = oldText; }
        if (res.success) {
          toast(res.message || '操作成功', 'success');
          if (opts.onSuccess) opts.onSuccess(res);
          else { Modal.closeAll(); setTimeout(function () { location.reload(); }, 600); }
        } else {
          toast(res.message || '操作失败，请重试', 'error');
          if (opts.onFail) opts.onFail(res);
        }
      }).catch(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = oldText; }
        toast('网络异常，请重试', 'error');
      });
    });
  }
  window.ajaxSubmit = ajaxSubmit;

  /* ---------- 通用 CRUD 绑定 ---------- */
  window.bindCrud = function (cfg) {
    cfg = cfg || {};
    // 打开新增弹窗
    document.querySelectorAll('[data-act="create"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var form = document.getElementById(cfg.formId);
        if (form) form.reset();
        if (cfg.titleId) document.getElementById(cfg.titleId).textContent = '新增';
        form.action = cfg.storeUrl;
        Modal.open(cfg.modalId);
      });
    });

    // 打开编辑弹窗（AJAX 加载数据）
    document.querySelectorAll('[data-act="edit"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        var form = document.getElementById(cfg.formId);
        form.reset();
        form.action = cfg.updateUrl + '&id=' + id;
        if (cfg.titleId) document.getElementById(cfg.titleId).textContent = '编辑';
        req(cfg.editUrl + '&id=' + id).then(function (res) {
          // res 为记录对象
          Object.keys(res).forEach(function (k) {
            var field = form.querySelector('[name="' + k + '"]');
            if (!field) return;
            // 下拉框若不含当前值（如历史手填单位），自动补一个选项，保证回显
            if (field.tagName === 'SELECT') {
              var v = res[k] == null ? '' : String(res[k]);
              var has = Array.prototype.some.call(field.options, function (o) { return o.value === v; });
              if (!has && v !== '') {
                var opt = document.createElement('option');
                opt.value = v; opt.textContent = v;
                field.appendChild(opt);
              }
            }
            field.value = res[k];
          });
          if (cfg.afterEdit) cfg.afterEdit(res);
          Modal.open(cfg.modalId);
        });
      });
    });

    // 删除
    document.querySelectorAll('[data-act="delete"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id');
        confirmDelete({
          onOk: function () {
            req(cfg.destroyUrl + '&id=' + id, 'POST').then(function (res) {
              if (res.success) { toast(res.message || '删除成功', 'success'); setTimeout(function () { location.reload(); }, 600); }
              else toast(res.message || '删除失败', 'error');
            });
          }
        });
      });
    });

    // 表单提交
    var form = document.getElementById(cfg.formId);
    if (form) ajaxSubmit(form, {});
  };

  /* ---------- 通用工具 ---------- */
  window.Api = { get: function (u) { return req(u, 'GET'); }, post: function (u, d) { return req(u, 'POST', d); } };

  /* ---------- 列表多选 / 批量操作条（全选 + 选中项操作下拉） ---------- */

  /**
   * 批量操作绑定：在表格上方生成 [☑ 全选] ... [选中项：打印/编辑/删除 ▼] 操作条
   * cfg:
   *   printUrl:  string 或 function()（打印基础地址，自动追加 &ids=1,2,3）
   *   deleteUrl: string（逐条删除接口地址）
   *   editFn:    function(id)（编辑回调；批量编辑只允许勾选恰好 1 条）
   * 页面需有 .table-wrap 表格容器、.row-check 行勾选框；表头可放 .sel-all 全选框
   * 勾选状态按路由用 localStorage 跨页/刷新记忆：翻页、搜索后再打印仍包含之前勾选的记录
   */
  window.bindBatch = function (cfg) {
    cfg = cfg || {};
    var wrap = document.querySelector('.table-wrap');
    if (!wrap) return;

    // ---- 跨页勾选记忆（按当前路由隔离） ----
    var routeKey = 'index';
    try {
      var rp = new URLSearchParams(location.search);
      routeKey = rp.get('r') || location.pathname;
    } catch (e) { routeKey = location.pathname; }
    var LS_KEY = 'erp_batch_sel_' + routeKey;
    function loadSel() { try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]') || []; } catch (e) { return []; } }
    function saveSel() { try { localStorage.setItem(LS_KEY, JSON.stringify(selStore)); } catch (e) {} }
    var selStore = loadSel().map(String);
    function selAdd(ids) {
      ids.forEach(function (id) { if (selStore.indexOf(String(id)) < 0) selStore.push(String(id)); });
      saveSel();
    }
    function selRemove(ids) {
      selStore = selStore.filter(function (id) { return ids.indexOf(String(id)) < 0; });
      saveSel();
    }

    // 按权限/配置组装操作选项
    var actions = [];
    if (cfg.printUrl) actions.push({ v: 'print', t: '打印' });
    if (typeof cfg.editFn === 'function') actions.push({ v: 'edit', t: '编辑' });
    if (cfg.deleteUrl) actions.push({ v: 'delete', t: '删除' });
    if (!actions.length) {
      // 无任何可用操作时禁用勾选框，避免误操作
      document.querySelectorAll('.row-check, .sel-all').forEach(function (cb) { cb.disabled = true; });
      return;
    }

    var bar = document.createElement('div');
    bar.className = 'batch-bar';
    bar.innerHTML =
      '<label class="batch-select-all"><input type="checkbox" class="sel-all"> <span>全选</span></label>' +
      '<div class="batch-right">' +
        '<span class="batch-count" id="batchCount">已选 0 项</span>' +
        '<a href="javascript:;" id="batchClear" style="display:none;margin-left:8px;color:#e74c3c;font-size:12px">清空</a>' +
        '<select class="batch-action" id="batchAction" title="对选中记录执行操作">' +
          '<option value="">选中项：</option>' +
          actions.map(function (a) { return '<option value="' + a.v + '">' + a.t + '</option>'; }).join('') +
        '</select>' +
      '</div>';
    wrap.parentNode.insertBefore(bar, wrap);

    function rowBoxes() { return document.querySelectorAll('.row-check'); }
    function allBoxes() { return document.querySelectorAll('.sel-all'); }
    function syncState() {
      var rs = rowBoxes();
      var checked = document.querySelectorAll('.row-check:checked').length;
      var total = selStore.length;
      allBoxes().forEach(function (cb) {
        cb.checked = rs.length > 0 && checked === rs.length;
        cb.indeterminate = checked > 0 && checked < rs.length;
      });
      var el = document.getElementById('batchCount');
      if (el) el.textContent = '已选 ' + total + ' 项' + (total > checked ? '（含其他页 ' + (total - checked) + ' 项）' : '');
      var clr = document.getElementById('batchClear');
      if (clr) clr.style.display = total > 0 ? '' : 'none';
    }

    // 恢复本次会话中其他页/刷新前勾选的行
    rowBoxes().forEach(function (r) { if (selStore.indexOf(String(r.value)) >= 0) r.checked = true; });

    // 全选框（操作条 + 表头）联动所有行（仅作用于当前页可见行）
    allBoxes().forEach(function (cb) {
      cb.addEventListener('change', function () {
        var ids = [];
        rowBoxes().forEach(function (r) { r.checked = cb.checked; ids.push(r.value); });
        if (cb.checked) selAdd(ids); else selRemove(ids);
        syncState();
      });
    });
    rowBoxes().forEach(function (r) {
      r.addEventListener('change', function () {
        if (r.checked) selAdd([r.value]); else selRemove([r.value]);
        syncState();
      });
    });
    syncState();

    // 清空：移除所有记忆的勾选（含其他页）
    document.getElementById('batchClear').addEventListener('click', function () {
      selStore = [];
      saveSel();
      rowBoxes().forEach(function (r) { r.checked = false; });
      syncState();
    });

    function doPrint(ids) {
      var base = typeof cfg.printUrl === 'function' ? cfg.printUrl() : cfg.printUrl;
      window.open(base + '&ids=' + ids.join(','), '_blank');
    }

    function doDelete(ids) {
      confirmDelete({
        message: '确定要删除选中的 ' + ids.length + ' 条记录吗？此操作不可恢复。',
        onOk: function () {
          var done = 0, fail = 0;
          var next = function (i) {
            if (i >= ids.length) {
              if (fail > 0) toast('删除完成：成功 ' + done + ' 条，失败 ' + fail + ' 条', 'error');
              else toast('成功删除 ' + done + ' 条记录', 'success');
              selStore = [];
              saveSel();
              setTimeout(function () { location.reload(); }, 700);
              return;
            }
            Api.post(cfg.deleteUrl + '&id=' + ids[i]).then(function (res) {
              if (res.success) done++; else fail++;
              next(i + 1);
            }).catch(function () { fail++; next(i + 1); });
          };
          next(0);
        }
      });
    }

    document.getElementById('batchAction').addEventListener('change', function () {
      var act = this.value;
      this.value = ''; // 立即复位，便于重复选择
      if (!act) return;
      var ids = selStore.slice(); // 含其他页勾选的记录
      if (!ids.length) { toast('请先勾选记录', 'error'); return; }
      if (act === 'print') {
        doPrint(ids);
      } else if (act === 'delete') {
        doDelete(ids);
      } else if (act === 'edit') {
        if (ids.length !== 1) { toast('编辑操作只能选择一条记录', 'error'); return; }
        cfg.editFn(ids[0]);
      }
    });
  };
})();
