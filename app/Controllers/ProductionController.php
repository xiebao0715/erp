<?php
/**
 * 生产管理
 * - 生产方案（BOM）：维护每个可生产商品所需消耗的材料及单位用量
 * - 生产任务单：选择可生产商品 + 生产数量 + 目标产线 + 领料仓库 + 成品入库仓库，
 *   系统按 BOM 自动展开消耗材料
 * - 任务状态机：0待生产 → 2生产中 → 1已完成
 *   「开始生产」（或保存为生产中）时在同一事务内：校验材料库存 → 生成生产领料出库单（扣材料），
 *   但成品暂不入库；「完成生产」时再生成完工成品入库单（加成品）
 * - 生产领料出库单（outbound_orders.production_id）与完工入库单（inbound_orders.production_id）
 *   由本模块统一维护，出入库模块中仅可查看/打印，不可直接编辑或删除
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;
use Core\Permission;

final class ProductionController extends BaseController
{
    protected string $module = 'production';

    /** 开始生产 / 完成生产 归入「开始生产」权限 */
    protected array $actionMap = ['start' => 'start', 'complete' => 'start'];

    /** 列表页：生产任务 + 生产方案（页内双 Tab） */
    public function index(): void
    {
        $search = trim((string)input('q', ''));
        $statusFilter = trim((string)input('status', ''));
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (po.order_no LIKE :q1 OR p.name LIKE :q2 OR p.code LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }
        if (in_array($statusFilter, ['0', '1', '2', '3'], true)) {
            $where .= ' AND po.status = :st';
            $params['st'] = (int)$statusFilter;
        }

        $goodsCols = "(SELECT GROUP_CONCAT(m.name SEPARATOR '、') FROM production_items pi2
                      JOIN products m ON pi2.material_id = m.id WHERE pi2.order_id = po.id) AS material_names,
                     (SELECT COALESCE(SUM(pi3.quantity), 0) FROM production_items pi3 WHERE pi3.order_id = po.id) AS material_qty,
                     (SELECT COALESCE(SUM(pc.quantity), 0) FROM production_cancels pc WHERE pc.production_id = po.id AND pc.status = 0) AS cancel_pending_qty,
                     (SELECT COALESCE(SUM(pc.quantity), 0) FROM production_cancels pc WHERE pc.production_id = po.id AND pc.status = 1) AS cancel_done_qty,
                     (SELECT pc2.id FROM production_cancels pc2 WHERE pc2.production_id = po.id AND pc2.status = 0 ORDER BY pc2.id DESC LIMIT 1) AS cancel_pending_id";

        $countSql = "SELECT COUNT(*) FROM production_orders po
                     JOIN products p ON po.product_id = p.id
                     WHERE $where";
        $listSql  = "SELECT po.id, po.order_no, po.product_id, po.quantity, po.warehouse_id, po.production_line_id,
                            po.inbound_warehouse_id, po.operator, po.total_amount, po.production_date, po.status,
                            po.outbound_id, po.inbound_id, po.remark,
                            p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit,
                            w.name AS warehouse_name, iw.name AS inbound_warehouse_name,
                            pl.name AS line_name, pl.manager AS line_manager,
                            oo.order_no AS outbound_no, io.order_no AS inbound_no, $goodsCols
                     FROM production_orders po
                     JOIN products p ON po.product_id = p.id
                     LEFT JOIN warehouses w ON po.warehouse_id = w.id
                     LEFT JOIN warehouses iw ON po.inbound_warehouse_id = iw.id
                     LEFT JOIN production_lines pl ON po.production_line_id = pl.id
                     LEFT JOIN outbound_orders oo ON po.outbound_id = oo.id
                     LEFT JOIN inbound_orders io ON po.inbound_id = io.id
                     WHERE $where ORDER BY po.production_date DESC, po.id DESC";
        $page = $this->listData($countSql, $listSql, $params, 15);
        $page['search'] = $search;
        $page['status'] = $statusFilter;

        // 生产方案（数据量通常不大，一次加载；含成品/材料名称）
        $plans = $this->db->fetchAll(
            "SELECT b.id, b.product_id, b.material_id, b.quantity, b.unit, b.remark, b.operator,
                    p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit,
                    m.code AS material_code, m.name AS material_name, m.spec AS material_spec,
                    m.unit AS material_unit, m.cost AS material_cost
             FROM production_boms b
             JOIN products p ON b.product_id = p.id
             JOIN products m ON b.material_id = m.id
             ORDER BY p.code, b.id"
        );

        // 方案列表按「生产商品」分组：一个成品一行，汇总消耗种数/用量、下发人与备注
        $planGroups = [];
        foreach ($plans as $b) {
            $pid = (int)$b['product_id'];
            if (!isset($planGroups[$pid])) {
                $planGroups[$pid] = [
                    'first_id'       => (int)$b['id'],
                    'product_id'     => $pid,
                    'product_code'   => $b['product_code'],
                    'product_name'   => $b['product_name'],
                    'product_spec'   => $b['product_spec'],
                    'product_unit'   => $b['product_unit'],
                    'material_count' => 0,
                    'total_qty'      => 0.0,
                    'operators'      => [],
                    'remarks'        => [],
                ];
            }
            $g = &$planGroups[$pid];
            $g['material_count']++;
            $g['total_qty'] += (float)$b['quantity'];
            if ((string)$b['operator'] !== '') $g['operators'][$b['operator']] = true;
            if ((string)$b['remark'] !== '')   $g['remarks'][$b['remark']] = true;
            unset($g);
        }

        $this->view('production/index', [
            'title'       => '生产管理',
            'page'        => $page,
            'plans'       => $plans,
            'planGroups'  => $planGroups,
            'producible'  => $this->db->fetchAll("SELECT id, code, name, spec, unit FROM products WHERE status=1 AND is_producible=1 ORDER BY code"),
            'materials'   => $this->db->fetchAll(
                "SELECT p.id, p.code, p.name, p.spec, p.unit, p.cost,
                        COALESCE((SELECT SUM(i.quantity) FROM inventory i WHERE i.product_id = p.id), 0) AS stock
                 FROM products p WHERE p.status = 1 ORDER BY p.code"
            ),
            'warehouses'  => $this->db->fetchAll("SELECT id, name FROM warehouses WHERE status=1 ORDER BY name"),
            'lines'       => $this->db->fetchAll("SELECT id, code, name, manager FROM production_lines WHERE status=1 ORDER BY code"),
            'canCreate'   => Auth::instance()->isAdmin() || Permission::check($this->module, 'create'),
            'canEdit'     => Auth::instance()->isAdmin() || Permission::check($this->module, 'edit'),
            'canStart'    => Auth::instance()->isAdmin() || Permission::check($this->module, 'start'),
            'canCancel'   => Auth::instance()->isAdmin() || Permission::check($this->module, 'cancel'),
            'canReturn'   => Auth::instance()->isAdmin() || Permission::check($this->module, 'return'),
            'canDelete'   => Auth::instance()->isAdmin() || Permission::check($this->module, 'delete'),
            'canPrint'    => Auth::instance()->isAdmin() || Permission::check($this->module, 'print'),
        ]);
    }

    // ================= 生产方案（BOM） =================

    /** 查询某成品的生产方案（AJAX，任务弹窗选成品后调用） */
    public function planItems(): void
    {
        $productId = (int)input('product_id', 0);
        $rows = $this->db->fetchAll(
            "SELECT b.id, b.product_id, b.material_id, b.quantity AS bom_qty, b.unit, b.remark,
                    m.code AS material_code, m.name AS material_name, m.spec AS material_spec,
                    m.unit AS material_unit, m.cost AS material_cost
             FROM production_boms b
             JOIN products m ON b.material_id = m.id
             WHERE b.product_id = :pid
             ORDER BY b.id",
            ['pid' => $productId]
        );
        $this->json(['success' => true, 'items' => $rows]);
    }

    /** 新增方案（AJAX）：一个成品一次性保存多行消耗材料（整表替换） */
    public function planStore(): void
    {
        $data = $this->collectPlanInput();
        if ($err = $this->validatePlan($data)) {
            $this->fail($err);
        }
        try {
            $this->savePlanRows($data);
            $this->ok('生产方案保存成功，共 ' . count($data['rows']) . ' 项消耗材料');
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    /** 编辑方案（AJAX）：按成品整表替换其全部消耗材料行 */
    public function planUpdate(): void
    {
        $id = (int)input('id', 0);
        if (!$this->db->fetch("SELECT id FROM production_boms WHERE id = :id", ['id' => $id])) {
            $this->fail('方案不存在');
        }
        $data = $this->collectPlanInput();
        if ($err = $this->validatePlan($data)) {
            $this->fail($err);
        }
        try {
            $this->savePlanRows($data);
            $this->ok('生产方案编辑成功，共 ' . count($data['rows']) . ' 项消耗材料');
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    /** 删除方案（AJAX）：按成品删除其整套方案（pid 优先）；兼容单行删除（id） */
    public function planDestroy(): void
    {
        $pid = (int)input('pid', 0);
        if ($pid > 0) {
            if (!$this->db->fetch("SELECT id FROM production_boms WHERE product_id = :p LIMIT 1", ['p' => $pid])) {
                $this->fail('方案不存在');
            }
            try {
                $this->db->delete('production_boms', 'product_id = :p', ['p' => $pid]);
                $this->ok('方案删除成功');
            } catch (\Throwable) {
                $this->fail('操作失败，请重试');
            }
            return;
        }
        $id = (int)input('id', 0);
        if (!$this->db->fetch("SELECT id FROM production_boms WHERE id = :id", ['id' => $id])) {
            $this->fail('方案不存在');
        }
        try {
            $this->db->delete('production_boms', 'id = :id', ['id' => $id]);
            $this->ok('方案删除成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    // ================= 生产任务单 =================

    /** 任务编辑数据（AJAX） */
    public function edit(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch("SELECT * FROM production_orders WHERE id = :id", ['id' => $id]);
        if (!$order) {
            $this->fail('任务单不存在');
        }
        $items = $this->db->fetchAll(
            "SELECT i.*, m.code AS material_code, m.name AS material_name, m.spec AS material_spec, m.unit AS material_unit
             FROM production_items i
             LEFT JOIN products m ON i.material_id = m.id
             WHERE i.order_id = :id",
            ['id' => $id]
        );
        $this->json(['order' => $order, 'items' => $items]);
    }

    /** 新增任务（AJAX）：保存任务与材料快照；直接下达为生产中时立即领料出库（扣材料，成品不入库） */
    public function store(): void
    {
        $data = $this->collectTaskInput();
        if ($err = $this->validateTask($data)) {
            $this->fail($err);
        }
        try {
            $taskId = 0;
            $outboundId = 0;
            $this->db->transaction(function ($db) use ($data, &$taskId, &$outboundId) {
                $items = $this->buildItems($db, (int)$data['product_id'], (float)$data['quantity']);
                if (!$items) {
                    throw new \RuntimeException('该商品尚未配置生产方案，请先在「生产方案」中添加消耗材料');
                }
                $taskNo = gen_order_no('SC');
                $taskId = (int)$db->insert('production_orders', [
                    'order_no'             => $taskNo,
                    'product_id'           => $data['product_id'],
                    'quantity'             => $data['quantity'],
                    'warehouse_id'         => $data['warehouse_id'],
                    'production_line_id'   => $data['production_line_id'],
                    'inbound_warehouse_id' => $data['inbound_warehouse_id'],
                    'operator'             => $data['operator'],
                    'total_amount'         => $this->sumAmount($items),
                    'production_date'      => $data['production_date'],
                    'status'               => $data['status'],
                    'outbound_id'          => 0,
                    'inbound_id'           => 0,
                    'remark'               => $data['remark'],
                ]);
                $this->insertTaskItems($db, $taskId, $items);
                // 生产中：立即领料出库（扣减材料，成品暂不入库）
                if ((int)$data['status'] === 2) {
                    if ($errs = $this->checkStock($db, (int)$data['warehouse_id'], $items)) {
                        throw new \RuntimeException('库存不足：' . implode('，', $errs));
                    }
                    $outboundId = $this->createOutbound($db, $taskId, $taskNo, $data, $items);
                    $db->update('production_orders', ['outbound_id' => $outboundId], 'id = :id', ['id' => $taskId]);
                }
            });
            if ((int)$data['status'] === 2) {
                $this->ok('生产任务已下达并完成领料，材料已出库，成品待完工入库', ['outbound_id' => $outboundId]);
            }
            $this->ok('生产任务已下达（待生产）');
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    /**
     * 更新任务（AJAX）：
     * - 旧状态已完成：回滚成品入库单+领料出库单；旧状态生产中：回滚领料出库单
     * - 保存为生产中时按新明细重新领料出库；保存为待生产则不动库存
     */
    public function update(): void
    {
        $id = (int)input('id', 0);
        $old = $this->db->fetch("SELECT * FROM production_orders WHERE id = :id", ['id' => $id]);
        if (!$old) {
            $this->fail('任务单不存在');
        }
        $data = $this->collectTaskInput();
        if ($err = $this->validateTask($data)) {
            $this->fail($err);
        }
        if ($this->hasCancels((int)$old['id'])) {
            $this->fail('该任务存在取消生产记录，不能编辑；如确需调整请先删除任务后重新下达');
        }
        try {
            $newOutboundId = 0;
            $this->db->transaction(function ($db) use ($id, $old, $data, &$newOutboundId) {
                $oldStatus = (int)$old['status'];
                if ($oldStatus === 1) {
                    // 回滚旧完工结果：成品扣回、材料加回，并删除自动生成的出入库单
                    $this->rollbackOutbound($db, (int)$old['id'], (int)$old['outbound_id']);
                    $this->rollbackInbound($db, (int)$old['id'], (int)($old['inbound_id'] ?? 0));
                } elseif ($oldStatus === 2) {
                    // 生产中：材料已领料出库，先加回
                    $this->rollbackOutbound($db, (int)$old['id'], (int)$old['outbound_id']);
                }

                $items = $this->buildItems($db, (int)$data['product_id'], (float)$data['quantity']);
                if (!$items) {
                    throw new \RuntimeException('该商品尚未配置生产方案，请先在「生产方案」中添加消耗材料');
                }
                $db->delete('production_items', 'order_id = :id', ['id' => $id]);
                $db->update('production_orders', [
                    'product_id'           => $data['product_id'],
                    'quantity'             => $data['quantity'],
                    'warehouse_id'         => $data['warehouse_id'],
                    'production_line_id'   => $data['production_line_id'],
                    'inbound_warehouse_id' => $data['inbound_warehouse_id'],
                    'operator'             => $data['operator'],
                    'total_amount'         => $this->sumAmount($items),
                    'production_date'      => $data['production_date'],
                    'status'               => $data['status'],
                    'outbound_id'          => 0,
                    'inbound_id'           => 0,
                    'remark'               => $data['remark'],
                ], 'id = :id', ['id' => $id]);
                $this->insertTaskItems($db, $id, $items);

                // 保存为生产中：重新领料出库
                if ((int)$data['status'] === 2) {
                    if ($errs = $this->checkStock($db, (int)$data['warehouse_id'], $items)) {
                        throw new \RuntimeException('库存不足：' . implode('，', $errs));
                    }
                    $newOutboundId = $this->createOutbound($db, $id, (string)$old['order_no'], $data, $items);
                    $db->update('production_orders', ['outbound_id' => $newOutboundId], 'id = :id', ['id' => $id]);
                }
            });
            $msg = '生产任务已更新';
            $oldStatus = (int)$old['status'];
            if ($oldStatus === 1) {
                $msg .= '，原完工出入库单已回滚';
            } elseif ($oldStatus === 2) {
                $msg .= '，原领料出库单已回滚';
            }
            if ((int)$data['status'] === 2) {
                $msg .= '，已按新明细重新领料出库';
                $this->ok($msg, ['outbound_id' => $newOutboundId]);
            }
            $this->ok($msg);
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    /** 删除任务（AJAX）：先回滚取消单退回的材料，再回滚领料出库/完工入库 */
    public function destroy(): void
    {
        $id = (int)input('id', 0);
        $old = $this->db->fetch("SELECT id, status, outbound_id, inbound_id, warehouse_id FROM production_orders WHERE id = :id", ['id' => $id]);
        if (!$old) {
            $this->fail('任务单不存在');
        }
        try {
            $this->db->transaction(function ($db) use ($id, $old) {
                $oldStatus = (int)$old['status'];
                // 取消单及其退回台账仅清理记录（取消确认时库存与单据数量已同步扣减，回滚按当前单据量即可平衡）
                $this->rollbackCancels($db, $id, (int)$old['warehouse_id']);
                if ($oldStatus === 1) {
                    $this->rollbackOutbound($db, $id, (int)$old['outbound_id']);
                    $this->rollbackInbound($db, $id, (int)($old['inbound_id'] ?? 0));
                } elseif ($oldStatus === 2) {
                    $this->rollbackOutbound($db, $id, (int)$old['outbound_id']);
                }
                // 待生产(0)/已取消(3)：无领料/入库动作或已全部取消，仅删记录
                $db->delete('production_items', 'order_id = :id', ['id' => $id]);
                $db->delete('production_orders', 'id = :id', ['id' => $id]);
            });
            $this->ok('任务单删除成功');
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    /** 待生产 → 生产中（AJAX）：事务内校验库存、生成领料出库单并扣减材料；成品暂不入库 */
    public function start(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch("SELECT * FROM production_orders WHERE id = :id", ['id' => $id]);
        if (!$order) {
            $this->fail('任务单不存在');
        }
        if ((int)$order['status'] !== 0) {
            $this->fail('只有待生产任务可以开始生产');
        }
        if ((int)$order['outbound_id'] > 0) {
            $this->fail('任务已领料，请刷新页面后重试');
        }
        $pendingCancel = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(quantity),0) FROM production_cancels WHERE production_id = :id AND status = 0",
            ['id' => $id]
        );
        if ($pendingCancel > 0) {
            $this->fail('存在待取消记录，请先在列表点「确认取消」后再开始生产');
        }

        $data = [
            'product_id'           => (int)$order['product_id'],
            'quantity'             => (float)$order['quantity'],
            'warehouse_id'         => (int)$order['warehouse_id'],
            'inbound_warehouse_id' => (int)($order['inbound_warehouse_id'] ?? 0),
            'operator'             => (string)$order['operator'],
            'production_date'      => (string)$order['production_date'],
        ];
        $taskNo = (string)$order['order_no'];

        try {
            $outboundId = 0;
            $this->db->transaction(function ($db) use ($id, $taskNo, $data, &$outboundId) {
                // 按当前 BOM 展开并刷新快照
                $items = $this->buildItems($db, $data['product_id'], $data['quantity']);
                if (!$items) {
                    throw new \RuntimeException('该商品尚未配置生产方案或方案用量为 0，无法开始生产');
                }
                if ($errs = $this->checkStock($db, $data['warehouse_id'], $items)) {
                    throw new \RuntimeException('库存不足：' . implode('，', $errs));
                }
                $outboundId = $this->createOutbound($db, $id, $taskNo, $data, $items);

                $db->delete('production_items', 'order_id = :id', ['id' => $id]);
                $this->insertTaskItems($db, $id, $items);
                $db->update('production_orders', [
                    'status'       => 2,
                    'outbound_id'  => $outboundId,
                    'total_amount' => $this->sumAmount($items),
                ], 'id = :id', ['id' => $id]);
            });
            $this->ok('已开始生产：材料领料出库单已生成，成品待完工入库', [
                'outbound_id' => $outboundId,
            ]);
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    /** 生产中 → 已完成（AJAX）：材料已在开始生产时出库，此处仅按「任务数量−已取消数量」生成成品入库单 */
    public function complete(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch("SELECT * FROM production_orders WHERE id = :id", ['id' => $id]);
        if (!$order) {
            $this->fail('任务单不存在');
        }
        if ((int)$order['status'] === 1) {
            $this->fail('该任务已完成，请勿重复操作');
        }
        if ((int)$order['status'] !== 2) {
            $this->fail('请先点「开始生产」完成领料出库，再完成生产');
        }
        if ((int)$order['outbound_id'] <= 0) {
            $this->fail('任务尚未领料出库，请编辑任务后重新开始生产');
        }
        if ((int)($order['inbound_id'] ?? 0) > 0) {
            $this->fail('成品已入库，请刷新页面后重试');
        }
        if ((int)$order['inbound_warehouse_id'] <= 0) {
            $this->fail('任务未设置成品入库仓库，请先编辑任务');
        }
        $pendingCancel = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(quantity),0) FROM production_cancels WHERE production_id = :id AND status = 0",
            ['id' => $id]
        );
        if ($pendingCancel > 0) {
            $this->fail('存在待取消记录，请先在列表点「确认取消」后再完成生产');
        }
        // quantity 已是扣除已确认取消后的净额，完工入库数量直接取当前数量
        $finishedQty = round((float)$order['quantity'], 2);
        if ($finishedQty <= 0) {
            $this->fail('任务数量已全部取消，无需完工入库');
        }

        $data = [
            'product_id'           => (int)$order['product_id'],
            'quantity'             => $finishedQty,
            'finished_qty'         => $finishedQty,
            'warehouse_id'         => (int)$order['warehouse_id'],
            'inbound_warehouse_id' => (int)$order['inbound_warehouse_id'],
            'operator'             => (string)$order['operator'],
            'production_date'      => (string)$order['production_date'],
        ];
        $taskNo = (string)$order['order_no'];

        try {
            $inboundId = 0;
            $this->db->transaction(function ($db) use ($id, $taskNo, $data, &$inboundId) {
                $inboundId = $this->createInbound($db, $id, $taskNo, $data);
                $db->update('production_orders', [
                    'status'     => 1,
                    'inbound_id' => $inboundId,
                ], 'id = :id', ['id' => $id]);
            });
            $this->ok('生产已完成：成品 ' . rtrim(rtrim(number_format($finishedQty, 2), '0'), '.') . ' 已入库', [
                'outbound_id' => (int)$order['outbound_id'],
                'inbound_id'  => $inboundId,
            ]);
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    // ================= 取消生产（生产中按比例退回消耗材料） =================

    /** 取消生产弹窗数据（AJAX）：任务/已取消量/材料退回明细预览/已有取消单 */
    public function cancelItems(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT po.*, p.name AS product_name, p.code AS product_code, p.unit AS product_unit, w.name AS warehouse_name
             FROM production_orders po
             JOIN products p ON po.product_id = p.id
             LEFT JOIN warehouses w ON po.warehouse_id = w.id
             WHERE po.id = :id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('任务单不存在');
        }
        if ((int)$order['status'] === 3) {
            $this->fail('该任务已取消，不能再操作');
        }
        $materials = $this->db->fetchAll(
            "SELECT pi.material_id, pi.quantity, pi.unit_price,
                    m.code AS material_code, m.name AS material_name, m.spec AS material_spec, m.unit AS material_unit
             FROM production_items pi
             LEFT JOIN products m ON pi.material_id = m.id
             WHERE pi.order_id = :id
             ORDER BY pi.id",
            ['id' => $id]
        );
        $cancels = $this->db->fetchAll(
            "SELECT id, order_no, quantity, status, cancel_date, operator, remark
             FROM production_cancels WHERE production_id = :id ORDER BY id DESC",
            ['id' => $id]
        );
        $pendingQty = 0.0;
        $doneQty = 0.0;
        foreach ($cancels as $c) {
            if ((int)$c['status'] === 1) {
                $doneQty += (float)$c['quantity'];
            } else {
                $pendingQty += (float)$c['quantity'];
            }
        }
        // quantity 已是扣除已确认取消后的净额，可取消余量 = 净额 − 待取消量
        $this->json([
            'success'       => true,
            'order'         => $order,
            'materials'     => $materials,
            'cancels'       => $cancels,
            'pending_qty'   => round($pendingQty, 2),
            'done_qty'      => round($doneQty, 2),
            'remain_qty'    => round((float)$order['quantity'] - $pendingQty, 2),
        ]);
    }

    /** 登记取消生产（AJAX）：待生产/生产中/已完成均可；生成「待取消」单并保存按比例退回的材料明细，暂不动库存 */
    public function cancelStore(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch("SELECT * FROM production_orders WHERE id = :id", ['id' => $id]);
        if (!$order) {
            $this->fail('任务单不存在');
        }
        if ((int)$order['status'] === 3) {
            $this->fail('该任务已取消，不能再操作');
        }
        $qty = round((float)input('quantity', 0), 2);
        if ($qty <= 0) {
            $this->fail('取消数量必须大于 0');
        }
        $remark = trim((string)input('remark', ''));

        try {
            $cancelId = 0;
            $this->db->transaction(function ($db) use ($id, $order, $qty, $remark, &$cancelId) {
                $pending = (float)$db->fetchColumn(
                    "SELECT COALESCE(SUM(quantity),0) FROM production_cancels WHERE production_id = :id AND status = 0",
                    ['id' => $id]
                );
                if ($pending > 0) {
                    throw new \RuntimeException('已有待取消记录，请先在列表点「确认取消」');
                }
                // quantity 已是扣除已确认取消后的净额
                $remain = round((float)$order['quantity'], 2);
                if ($qty > $remain + 0.00001) {
                    throw new \RuntimeException('取消数量 ' . $qty . ' 不能大于本单可取消数量 ' . $remain);
                }
                $materials = $db->fetchAll(
                    "SELECT material_id, quantity, unit_price FROM production_items WHERE order_id = :id",
                    ['id' => $id]
                );
                $taskStatus = (int)$order['status'];
                if (!$materials && $taskStatus !== 0) {
                    throw new \RuntimeException('任务缺少材料快照，无法取消');
                }
                $ratio = (float)$order['quantity'] > 0 ? $qty / (float)$order['quantity'] : 0.0;
                $rows = [];
                $totalAmount = 0.0;
                foreach ($materials as $m) {
                    $backQty = round((float)$m['quantity'] * $ratio, 2);
                    if ($backQty <= 0) {
                        continue;
                    }
                    $amount = round($backQty * (float)$m['unit_price'], 2);
                    $rows[] = [
                        'material_id' => (int)$m['material_id'],
                        'quantity'    => $backQty,
                        'unit_price'  => (float)$m['unit_price'],
                        'amount'      => $amount,
                    ];
                    $totalAmount += $amount;
                }
                if (!$rows && $taskStatus !== 0) {
                    throw new \RuntimeException('没有可退回的消耗材料');
                }
                $cancelId = (int)$db->insert('production_cancels', [
                    'order_no'      => gen_order_no('CX'),
                    'production_id' => $id,
                    'quantity'      => $qty,
                    'warehouse_id'  => (int)$order['warehouse_id'],
                    'operator'      => Auth::instance()->name(),
                    'total_amount'  => round($totalAmount, 2),
                    'cancel_date'   => date('Y-m-d'),
                    'status'        => 0,
                    'remark'        => $remark,
                ]);
                foreach ($rows as $r) {
                    $db->insert('production_cancel_items', [
                        'cancel_id'   => $cancelId,
                        'material_id' => $r['material_id'],
                        'quantity'    => $r['quantity'],
                        'unit_price'  => $r['unit_price'],
                        'amount'      => $r['amount'],
                        'remark'      => '取消生产退回：' . $order['order_no'],
                    ]);
                }
            });
            $this->ok('已登记取消生产（待取消），点「确认取消」后生效：生产数量扣减、消耗材料退回领料仓库', ['id' => $cancelId]);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /**
     * 确认取消（AJAX）：待取消 → 已取消，同一事务内：
     * 1. 生产中/已完成任务：按比例把消耗材料加回领料仓库；已完成任务另扣减成品库存（减少生产商品数量）
     * 2. 同步缩减任务材料快照与生产数量；数量减到 0 时任务自动变为「已取消」
     */
    public function cancelComplete(): void
    {
        $id = (int)input('id', 0);
        $cancel = $this->db->fetch("SELECT * FROM production_cancels WHERE id = :id", ['id' => $id]);
        if (!$cancel) {
            $this->fail('取消单不存在');
        }
        if ((int)$cancel['status'] !== 0) {
            $this->fail('该取消单已确认，请勿重复操作');
        }
        try {
            $autoCancelled = false;
            $this->db->transaction(function ($db) use ($id, $cancel, &$autoCancelled) {
                $task = $db->fetch("SELECT * FROM production_orders WHERE id = :id", ['id' => $cancel['production_id']]);
                if (!$task) {
                    throw new \RuntimeException('生产任务不存在，可能已被删除');
                }
                $taskStatus = (int)$task['status'];
                if ($taskStatus === 3) {
                    throw new \RuntimeException('任务已取消，无需再确认');
                }
                $qty = (float)$cancel['quantity'];
                $rows = $db->fetchAll(
                    "SELECT material_id, quantity, unit_price, amount FROM production_cancel_items WHERE cancel_id = :id",
                    ['id' => $id]
                );
                $materialReturned = 0;
                $productDeducted = 0;
                // 已领料（生产中/已完成）：消耗材料按比例退回领料仓库
                if ($taskStatus === 2 || $taskStatus === 1) {
                    foreach ($rows as $r) {
                        $this->adjustInventory($db, (int)$cancel['warehouse_id'], (int)$r['material_id'], (float)$r['quantity']);
                    }
                    $materialReturned = 1;
                }
                // 已完成：成品同步出库扣减（减少生产商品数量），库存不足则阻止
                if ($taskStatus === 1) {
                    $this->adjustInventory($db, (int)$task['inbound_warehouse_id'], (int)$task['product_id'], -$qty);
                    $productDeducted = 1;
                }
                // 同步到退回管理台账（库存已在本事务变动，退回单直接为已完成状态，仅作记录）
                // 备注标明取消时任务所处阶段：生产中退回 / 完成后退回（待生产取消不生成台账）
                $stageTxt = $taskStatus === 1 ? '完成后' : '生产中';
                $operator = Auth::instance()->name();
                if ($materialReturned === 1 && $rows) {
                    $retId = (int)$db->insert('return_orders', [
                        'order_no'             => gen_order_no('RTN'),
                        'return_type'          => 1,
                        'source_id'            => (int)$task['outbound_id'],
                        'production_cancel_id' => $id,
                        'warehouse_id'         => (int)$cancel['warehouse_id'],
                        'customer_id'          => 0,
                        'supplier_id'          => 0,
                        'operator'             => $operator,
                        'total_amount'         => (float)$cancel['total_amount'],
                        'return_date'          => date('Y-m-d'),
                        'status'               => 1,
                        'remark'               => '生产取消退回（' . $stageTxt . '取消 · 取消单 ' . $cancel['order_no'] . '）',
                    ]);
                    foreach ($rows as $r) {
                        $db->insert('return_items', [
                            'order_id'   => $retId,
                            'product_id' => (int)$r['material_id'],
                            'quantity'   => (float)$r['quantity'],
                            'unit_price' => (float)$r['unit_price'],
                            'amount'     => (float)$r['amount'],
                            'remark'     => '生产取消退回：' . $task['order_no'],
                        ]);
                    }
                }
                if ($productDeducted === 1) {
                    $cost = (float)$db->fetchColumn("SELECT cost FROM products WHERE id = :id", ['id' => $task['product_id']]);
                    $retId = (int)$db->insert('return_orders', [
                        'order_no'             => gen_order_no('RTN'),
                        'return_type'          => 2,
                        'source_id'            => (int)($task['inbound_id'] ?? 0),
                        'production_cancel_id' => $id,
                        'warehouse_id'         => (int)$task['inbound_warehouse_id'],
                        'customer_id'          => 0,
                        'supplier_id'          => 0,
                        'operator'             => $operator,
                        'total_amount'         => round($qty * $cost, 2),
                        'return_date'          => date('Y-m-d'),
                        'status'               => 1,
                        'remark'               => '生产取消扣减成品（完成后取消 · 取消单 ' . $cancel['order_no'] . '）',
                    ]);
                    $db->insert('return_items', [
                        'order_id'   => $retId,
                        'product_id' => (int)$task['product_id'],
                        'quantity'   => $qty,
                        'unit_price' => $cost,
                        'amount'     => round($qty * $cost, 2),
                        'remark'     => '生产取消扣减成品：' . $task['order_no'],
                    ]);
                }
                // 同步扣减生产领料出库单明细数量（出库管理显示实际领料量）
                if ($materialReturned === 1 && (int)$task['outbound_id'] > 0) {
                    foreach ($rows as $r) {
                        $db->query(
                            "UPDATE outbound_items
                             SET quantity = GREATEST(0, ROUND(quantity - :q, 2)),
                                 amount = ROUND(GREATEST(0, ROUND(quantity - :q2, 2)) * unit_price, 2)
                             WHERE order_id = :oid AND product_id = :pid",
                            ['q' => (float)$r['quantity'], 'q2' => (float)$r['quantity'], 'oid' => (int)$task['outbound_id'], 'pid' => (int)$r['material_id']]
                        );
                    }
                    $db->query(
                        "UPDATE outbound_orders o SET o.total_amount =
                         (SELECT COALESCE(SUM(oi.amount),0) FROM outbound_items oi WHERE oi.order_id = o.id)
                         WHERE o.id = :id",
                        ['id' => (int)$task['outbound_id']]
                    );
                }
                // 同步扣减生产入库单成品数量（入库管理显示实际入库量）
                if ($productDeducted === 1 && (int)($task['inbound_id'] ?? 0) > 0) {
                    $db->query(
                        "UPDATE inbound_items
                         SET quantity = GREATEST(0, ROUND(quantity - :q, 2)),
                             amount = ROUND(GREATEST(0, ROUND(quantity - :q2, 2)) * unit_price, 2)
                         WHERE order_id = :oid AND product_id = :pid",
                        ['q' => $qty, 'q2' => $qty, 'oid' => (int)$task['inbound_id'], 'pid' => (int)$task['product_id']]
                    );
                    $db->query(
                        "UPDATE inbound_orders o SET o.total_amount =
                         (SELECT COALESCE(SUM(ii.amount),0) FROM inbound_items ii WHERE ii.order_id = o.id)
                         WHERE o.id = :id",
                        ['id' => (int)$task['inbound_id']]
                    );
                }
                // 缩减任务材料快照（后续取消/领料按剩余量计算）
                foreach ($rows as $r) {
                    $db->query(
                        "UPDATE production_items SET quantity = GREATEST(0, ROUND(quantity - :q, 2))
                         WHERE order_id = :oid AND material_id = :mid",
                        ['q' => (float)$r['quantity'], 'oid' => $cancel['production_id'], 'mid' => (int)$r['material_id']]
                    );
                }
                // 同步减少生产数量；减到 0 任务自动取消
                $newQty = round((float)$task['quantity'] - $qty, 2);
                if ($newQty <= 0.00001) {
                    $autoCancelled = true;
                    $db->update('production_orders', ['quantity' => 0, 'status' => 3], 'id = :id', ['id' => $task['id']]);
                    $db->query("UPDATE production_items SET quantity = 0 WHERE order_id = :oid", ['oid' => $cancel['production_id']]);
                } else {
                    $db->update('production_orders', ['quantity' => $newQty], 'id = :id', ['id' => $task['id']]);
                }
                $db->update('production_cancels', [
                    'status'            => 1,
                    'material_returned' => $materialReturned,
                    'product_deducted'  => $productDeducted,
                    'task_status'       => $taskStatus,
                ], 'id = :id', ['id' => $id]);
            });
            $msg = '已确认取消：生产数量已扣减';
            if ($autoCancelled) {
                $msg .= '，数量已减为 0，任务自动取消';
            }
            $this->ok($msg);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 单张任务打印页 */
    public function detail(): void
    {
        $id = (int)input('id', 0);
        $order = $this->loadOrder($id);
        if (!$order) {
            echo '<div style="padding:30px;text-align:center;color:#999">生产任务单不存在</div>';
            return;
        }
        echo $this->render('production/print', [
            'title'    => '生产任务单详情',
            'orders'   => [$order],
            'itemsByOrder' => [$id => $this->loadItems($id)],
            'siteName' => $this->siteName(),
            'printer'  => Auth::instance()->name(),
        ]);
    }

    /** 批量打印（支持 ids 指定） */
    public function print(): void
    {
        $where = '1=1';
        $params = [];
        $idsRaw = trim((string)input('ids', ''));
        if ($idsRaw !== '') {
            $idList = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn ($v) => $v > 0));
            if ($idList) {
                $where .= ' AND po.id IN (' . implode(',', $idList) . ')';
            }
        }
        $orders = $this->db->fetchAll(
            "SELECT po.*, p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit,
                    w.name AS warehouse_name, iw.name AS inbound_warehouse_name,
                    pl.name AS line_name, pl.manager AS line_manager,
                    oo.order_no AS outbound_no, io.order_no AS inbound_no,
                    (SELECT COALESCE(SUM(pc.quantity),0) FROM production_cancels pc WHERE pc.production_id = po.id AND pc.status = 0) AS cancel_pending_qty,
                    (SELECT COALESCE(SUM(pc.quantity),0) FROM production_cancels pc WHERE pc.production_id = po.id AND pc.status = 1) AS cancel_done_qty
             FROM production_orders po
             JOIN products p ON po.product_id = p.id
             LEFT JOIN warehouses w ON po.warehouse_id = w.id
             LEFT JOIN warehouses iw ON po.inbound_warehouse_id = iw.id
             LEFT JOIN production_lines pl ON po.production_line_id = pl.id
             LEFT JOIN outbound_orders oo ON po.outbound_id = oo.id
             LEFT JOIN inbound_orders io ON po.inbound_id = io.id
             WHERE $where ORDER BY po.production_date DESC, po.id DESC",
            $params
        );
        $itemsByOrder = [];
        foreach ($orders as $o) {
            $itemsByOrder[(int)$o['id']] = $this->loadItems((int)$o['id']);
        }
        echo $this->render('production/print', [
            'title'    => '生产任务单',
            'orders'   => $orders,
            'itemsByOrder' => $itemsByOrder,
            'siteName' => $this->siteName(),
            'printer'  => Auth::instance()->name(),
        ]);
    }

    // ================= 内部辅助 =================

    /**
     * 收集方案表单：成品 + 多行消耗材料（material_id[]/quantity[]/remark[]）
     * 单位始终跟随材料档案，不信任前端传值
     */
    private function collectPlanInput(): array
    {
        $materialIds = input('material_id', []);
        $quantities  = input('quantity', []);
        $remarks     = input('remark', []);
        if (!is_array($materialIds)) {
            $materialIds = [];
        }
        if (!is_array($quantities)) {
            $quantities = [];
        }
        if (!is_array($remarks)) {
            $remarks = [];
        }

        $rows = [];
        foreach ($materialIds as $i => $mid) {
            $mid = (int)$mid;
            if ($mid <= 0) {
                continue; // 空白行直接忽略
            }
            $unit = '';
            $m = $this->db->fetch("SELECT unit FROM products WHERE id = :id", ['id' => $mid]);
            if ($m) {
                $unit = (string)$m['unit'];
            }
            $rows[] = [
                'material_id' => $mid,
                'quantity'    => (float)($quantities[$i] ?? 0),
                'unit'        => $unit,
                'remark'      => trim((string)($remarks[$i] ?? '')),
            ];
        }

        return [
            'product_id' => (int)input('product_id', 0),
            'rows'       => $rows,
        ];
    }

    /** 校验成品及每行材料；方案只定义用量，不校验库存 */
    private function validatePlan(array $d): ?string
    {
        if ($d['product_id'] <= 0) {
            return '请选择生产商品';
        }
        $p = $this->db->fetch("SELECT id, is_producible, status FROM products WHERE id = :id", ['id' => $d['product_id']]);
        if (!$p) {
            return '生产商品不存在';
        }
        if ((int)$p['is_producible'] !== 1) {
            return '该商品未标记为「可生产」，请先在商品管理中设置';
        }
        if ((int)$p['status'] !== 1) {
            return '生产商品已停用，不能配置方案';
        }
        if (!$d['rows']) {
            return '请至少添加一种消耗商品';
        }

        $seen = [];
        foreach ($d['rows'] as $idx => $r) {
            $label = '第 ' . ($idx + 1) . ' 行';
            if ($r['material_id'] === $d['product_id']) {
                return $label . '：消耗商品不能与生产商品相同';
            }
            $m = $this->db->fetch("SELECT id, name, status FROM products WHERE id = :id", ['id' => $r['material_id']]);
            if (!$m) {
                return $label . '：消耗商品不存在';
            }
            if ((int)$m['status'] !== 1) {
                return $label . '：消耗商品「' . $m['name'] . '」已停用，不能配置方案';
            }
            if ($r['quantity'] <= 0) {
                return $label . '：「' . $m['name'] . '」单位用量必须大于 0';
            }
            if (isset($seen[$r['material_id']])) {
                return '消耗商品「' . $m['name'] . '」重复添加，请合并为一行';
            }
            $seen[$r['material_id']] = true;
            // 方案只定义单位用量，不校验库存；实际能否领料在下达生产任务时按仓库库存校验
        }
        return null;
    }

    /** 事务内按成品整表替换 BOM 行（历史任务已保存消耗快照，不受影响） */
    private function savePlanRows(array $data): void
    {
        $this->db->transaction(function ($db) use ($data) {
            $db->delete('production_boms', 'product_id = :p', ['p' => $data['product_id']]);
            foreach ($data['rows'] as $row) {
                $db->insert('production_boms', [
                    'product_id'  => $data['product_id'],
                    'material_id' => $row['material_id'],
                    'quantity'    => $row['quantity'],
                    'unit'        => $row['unit'],
                    'remark'      => $row['remark'],
                    'operator'    => Auth::instance()->name(),
                ]);
            }
        });
    }

    private function collectTaskInput(): array
    {
        // 表单只允许 待生产(0) / 生产中(2)；完工只能通过列表「完成生产」推进
        $status = (int)input('status', 0) === 2 ? 2 : 0;
        return [
            'product_id'           => (int)input('product_id', 0),
            'quantity'             => (float)input('quantity', 0),
            'warehouse_id'         => (int)input('warehouse_id', 0),
            'production_line_id'   => (int)input('production_line_id', 0),
            'inbound_warehouse_id' => (int)input('inbound_warehouse_id', 0),
            'operator'             => Auth::instance()->name(),
            'production_date'      => trim((string)input('production_date', date('Y-m-d'))),
            'status'               => $status,
            'remark'               => trim((string)input('remark', '')),
        ];
    }

    private function validateTask(array $d): ?string
    {
        if ($d['product_id'] <= 0) {
            return '请选择生产商品';
        }
        $p = $this->db->fetch("SELECT id, is_producible, status FROM products WHERE id = :id", ['id' => $d['product_id']]);
        if (!$p) {
            return '生产商品不存在';
        }
        if ((int)$p['is_producible'] !== 1) {
            return '该商品不是可生产商品';
        }
        if ((int)$p['status'] !== 1) {
            return '生产商品已停用';
        }
        if ($d['quantity'] <= 0) {
            return '生产数量必须大于 0';
        }
        if ($d['warehouse_id'] <= 0) {
            return '请选择领料仓库';
        }
        $w = $this->db->fetch("SELECT id, status FROM warehouses WHERE id = :id", ['id' => $d['warehouse_id']]);
        if (!$w || (int)$w['status'] !== 1) {
            return '领料仓库不存在或已停用';
        }
        if ($d['inbound_warehouse_id'] <= 0) {
            return '请选择成品入库仓库';
        }
        $iw = $this->db->fetch("SELECT id, status FROM warehouses WHERE id = :id", ['id' => $d['inbound_warehouse_id']]);
        if (!$iw || (int)$iw['status'] !== 1) {
            return '成品入库仓库不存在或已停用';
        }
        if ($d['production_line_id'] <= 0) {
            return '请选择目标产线';
        }
        $l = $this->db->fetch("SELECT id, status FROM production_lines WHERE id = :id", ['id' => $d['production_line_id']]);
        if (!$l) {
            return '目标产线不存在';
        }
        if ((int)$l['status'] !== 1) {
            return '目标产线已停用';
        }
        if ($d['production_date'] === '') {
            return '请选择生产日期';
        }
        return null;
    }

    /**
     * 按生产方案展开本次任务的消耗材料
     * 每行：material_id / quantity（单位用量×生产数量）/ unit_price（材料成本价）/ amount
     */
    private function buildItems($db, int $productId, float $taskQty): array
    {
        $rows = $db->fetchAll(
            "SELECT b.material_id, b.quantity AS bom_qty, b.remark AS bom_remark,
                    m.unit, m.cost
             FROM production_boms b
             JOIN products m ON b.material_id = m.id
             WHERE b.product_id = :pid
             ORDER BY b.id",
            ['pid' => $productId]
        );
        $items = [];
        foreach ($rows as $r) {
            $qty = round((float)$r['bom_qty'] * $taskQty, 2);
            if ($qty <= 0) {
                continue;
            }
            $price = (float)$r['cost'];
            $items[] = [
                'material_id' => (int)$r['material_id'],
                'quantity'    => $qty,
                'unit_price'  => $price,
                'amount'      => round($qty * $price, 2),
                'remark'      => (string)$r['bom_remark'],
            ];
        }
        return $items;
    }

    private function insertTaskItems($db, int $taskId, array $items): void
    {
        foreach ($items as $row) {
            $db->insert('production_items', [
                'order_id'   => $taskId,
                'material_id' => $row['material_id'],
                'quantity'   => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'amount'     => $row['amount'],
                'remark'     => $row['remark'],
            ]);
        }
    }

    private function sumAmount(array $items): float
    {
        $sum = 0.0;
        foreach ($items as $r) {
            $sum += (float)$r['amount'];
        }
        return round($sum, 2);
    }

    /** 库存预检（同一事务连接，编辑回滚后调用可读到恢复后的库存） */
    private function checkStock($db, int $warehouseId, array $items): array
    {
        $errs = [];
        foreach ($items as $row) {
            $stock = (float)$db->fetchColumn(
                "SELECT quantity FROM inventory WHERE warehouse_id = :w AND product_id = :p",
                ['w' => $warehouseId, 'p' => $row['material_id']]
            );
            if ($stock < (float)$row['quantity']) {
                $m = $db->fetch("SELECT name FROM products WHERE id = :id", ['id' => $row['material_id']]);
                $errs[] = ($m['name'] ?? '商品' . $row['material_id'])
                    . '（需要 ' . $row['quantity'] . '，库存 ' . $stock . '）';
            }
        }
        return $errs;
    }

    /** 库存增量调整（负值扣减；无库存行扣减抛错） */
    private function adjustInventory($db, int $warehouseId, int $productId, float $delta): void
    {
        if ($warehouseId <= 0 || $productId <= 0 || $delta == 0.0) {
            return;
        }
        $exists = $db->fetch(
            "SELECT id, quantity FROM inventory WHERE warehouse_id = :w AND product_id = :p",
            ['w' => $warehouseId, 'p' => $productId]
        );
        if ($exists) {
            $newQty = (float)$exists['quantity'] + $delta;
            if ($newQty < 0) {
                throw new \RuntimeException('库存不足，无法领料出库');
            }
            $db->update('inventory', ['quantity' => $newQty], 'id = :id', ['id' => $exists['id']]);
        } elseif ($delta < 0) {
            throw new \RuntimeException('库存不足，无法领料出库');
        } else {
            $db->insert('inventory', [
                'warehouse_id' => $warehouseId,
                'product_id'   => $productId,
                'quantity'     => $delta,
            ]);
        }
    }

    /** 生成生产领料出库单（已出库状态，立即扣减材料库存），返回出库单ID */
    private function createOutbound($db, int $taskId, string $taskNo, array $data, array $items): int
    {
        $outboundId = (int)$db->insert('outbound_orders', [
            'order_no'      => gen_order_no('OUT'),
            'warehouse_id'  => $data['warehouse_id'],
            'customer_id'   => 0,
            'production_id' => $taskId,
            'operator'      => $data['operator'],
            'total_amount'  => $this->sumAmount($items),
            'outbound_date' => $data['production_date'],
            'status'        => 1,
            'remark'        => '生产领料：' . $taskNo,
        ]);
        foreach ($items as $row) {
            $db->insert('outbound_items', [
                'order_id'   => $outboundId,
                'product_id' => $row['material_id'],
                'quantity'   => $row['quantity'],
                'unit_price' => $row['unit_price'],
                'amount'     => $row['amount'],
                'remark'     => $row['remark'] !== '' ? $row['remark'] : ('生产领料：' . $taskNo),
            ]);
            $this->adjustInventory($db, (int)$data['warehouse_id'], (int)$row['material_id'], -(float)$row['quantity']);
        }
        return $outboundId;
    }

    /** 回滚生产领料出库单：恢复库存并删除出库单/明细（找不到则跳过） */
    private function rollbackOutbound($db, int $taskId, int $outboundId): void
    {
        if ($outboundId <= 0) {
            // 兜底：按 production_id 反查（历史数据 outbound_id 缺失时）
            $linked = $db->fetch(
                "SELECT id FROM outbound_orders WHERE production_id = :pid ORDER BY id DESC",
                ['pid' => $taskId]
            );
            if (!$linked) {
                return;
            }
            $outboundId = (int)$linked['id'];
        }
        $order = $db->fetch(
            "SELECT id, warehouse_id, status FROM outbound_orders WHERE id = :id AND production_id = :pid",
            ['id' => $outboundId, 'pid' => $taskId]
        );
        if (!$order) {
            return;
        }
        if ((int)$order['status'] === 1) {
            $rows = $db->fetchAll(
                "SELECT product_id, quantity FROM outbound_items WHERE order_id = :id",
                ['id' => $outboundId]
            );
            foreach ($rows as $r) {
                $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$r['product_id'], (float)$r['quantity']);
            }
        }
        $db->delete('outbound_items', 'order_id = :id', ['id' => $outboundId]);
        $db->delete('outbound_orders', 'id = :id', ['id' => $outboundId]);
    }

    /** 生成完工成品入库单（已入库状态，立即增加成品库存），返回入库单ID */
    private function createInbound($db, int $taskId, string $taskNo, array $data): int
    {
        $product = $db->fetch("SELECT cost FROM products WHERE id = :id", ['id' => $data['product_id']]);
        $price = (float)($product['cost'] ?? 0);
        $qty = (float)($data['finished_qty'] ?? $data['quantity']);
        $amount = round($qty * $price, 2);

        $inboundId = (int)$db->insert('inbound_orders', [
            'order_no'      => gen_order_no('IN'),
            'warehouse_id'  => $data['inbound_warehouse_id'],
            'supplier_id'   => 0,
            'production_id' => $taskId,
            'operator'      => $data['operator'],
            'total_amount'  => $amount,
            'inbound_date'  => $data['production_date'],
            'status'        => 1,
            'remark'        => '生产完工入库：' . $taskNo,
        ]);
        $db->insert('inbound_items', [
            'order_id'   => $inboundId,
            'product_id' => $data['product_id'],
            'quantity'   => $qty,
            'unit_price' => $price,
            'amount'     => $amount,
            'remark'     => '生产完工入库：' . $taskNo,
        ]);
        $this->adjustInventory($db, (int)$data['inbound_warehouse_id'], (int)$data['product_id'], $qty);
        return $inboundId;
    }

    /** 回滚完工成品入库单：扣回成品库存并删除入库单/明细（找不到则跳过；库存不足抛错） */
    private function rollbackInbound($db, int $taskId, int $inboundId): void
    {
        if ($inboundId <= 0) {
            // 兜底：按 production_id 反查
            $linked = $db->fetch(
                "SELECT id FROM inbound_orders WHERE production_id = :pid ORDER BY id DESC",
                ['pid' => $taskId]
            );
            if (!$linked) {
                return;
            }
            $inboundId = (int)$linked['id'];
        }
        $order = $db->fetch(
            "SELECT id, warehouse_id, status FROM inbound_orders WHERE id = :id AND production_id = :pid",
            ['id' => $inboundId, 'pid' => $taskId]
        );
        if (!$order) {
            return;
        }
        if ((int)$order['status'] === 1) {
            $rows = $db->fetchAll(
                "SELECT product_id, quantity FROM inbound_items WHERE order_id = :id",
                ['id' => $inboundId]
            );
            foreach ($rows as $r) {
                // 扣回成品；adjustInventory 在库存不足时会抛错，阻止错误回滚
                $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$r['product_id'], -(float)$r['quantity']);
            }
        }
        $db->delete('inbound_items', 'order_id = :id', ['id' => $inboundId]);
        $db->delete('inbound_orders', 'id = :id', ['id' => $inboundId]);
    }

    /** 任务是否存在取消记录（待取消/已取消） */
    private function hasCancels(int $taskId): bool
    {
        $cnt = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM production_cancels WHERE production_id = :id",
            ['id' => $taskId]
        );
        return $cnt > 0;
    }

    /**
     * 清理任务的全部取消单（删除任务前调用）：
     * 确认取消时库存、领料/入库单据数量均已同步扣减，删除任务只需按当前单据量回滚，
     * 这里仅删除取消单及其在退回管理的台账记录，不再动库存。
     */
    private function rollbackCancels($db, int $taskId, int $warehouseId): void
    {
        $cancels = $db->fetchAll(
            "SELECT id FROM production_cancels WHERE production_id = :id",
            ['id' => $taskId]
        );
        foreach ($cancels as $c) {
            // 同步删除该取消单在退回管理生成的台账单（仅删记录）
            $retIds = $db->fetchAll("SELECT id FROM return_orders WHERE production_cancel_id = :id", ['id' => $c['id']]);
            foreach ($retIds as $ro) {
                $db->delete('return_items', 'order_id = :id', ['id' => $ro['id']]);
            }
            $db->delete('return_orders', 'production_cancel_id = :id', ['id' => $c['id']]);
            $db->delete('production_cancel_items', 'cancel_id = :id', ['id' => $c['id']]);
            $db->delete('production_cancels', 'id = :id', ['id' => $c['id']]);
        }
    }

    private function loadOrder(int $id): ?array
    {
        return $this->db->fetch(
            "SELECT po.*, p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit,
                    w.name AS warehouse_name, iw.name AS inbound_warehouse_name,
                    pl.name AS line_name, pl.manager AS line_manager,
                    oo.order_no AS outbound_no, io.order_no AS inbound_no,
                    (SELECT COALESCE(SUM(pc.quantity),0) FROM production_cancels pc WHERE pc.production_id = po.id AND pc.status = 0) AS cancel_pending_qty,
                    (SELECT COALESCE(SUM(pc.quantity),0) FROM production_cancels pc WHERE pc.production_id = po.id AND pc.status = 1) AS cancel_done_qty
             FROM production_orders po
             JOIN products p ON po.product_id = p.id
             LEFT JOIN warehouses w ON po.warehouse_id = w.id
             LEFT JOIN warehouses iw ON po.inbound_warehouse_id = iw.id
             LEFT JOIN production_lines pl ON po.production_line_id = pl.id
             LEFT JOIN outbound_orders oo ON po.outbound_id = oo.id
             LEFT JOIN inbound_orders io ON po.inbound_id = io.id
             WHERE po.id = :id",
            ['id' => $id]
        ) ?: null;
    }

    private function loadItems(int $orderId): array
    {
        return $this->db->fetchAll(
            "SELECT i.*, m.code AS material_code, m.name AS material_name, m.spec AS material_spec, m.unit AS material_unit
             FROM production_items i
             LEFT JOIN products m ON i.material_id = m.id
             WHERE i.order_id = :id
             ORDER BY i.id",
            ['id' => $orderId]
        );
    }

    private function siteName(): string
    {
        $s = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'site_name'");
        return $s['value'] ?? '工厂仓库 ERP 管理系统';
    }
}
