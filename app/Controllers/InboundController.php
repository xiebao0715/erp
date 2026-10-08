<?php
/**
 * 入库管理
 * - 列表 / 新增 / 编辑 / 删除 / 打印
 * - 主表 + 明细行（商品 + 数量 + 单价 + 金额）
 * - 入库时同步更新 inventory（warehouse_id + product_id += quantity）
 * - 删除时回滚 inventory（仅 status=1 的入库单才会扣减）
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;
use Core\Csrf;

final class InboundController extends BaseController
{
    protected string $module = 'inbound';

    /** 列表页 */
    public function index(): void
    {
        $search = trim((string)input('q', ''));
        $categoryId = (int)input('category_id', 0);
        $from = trim((string)input('from', ''));
        $to = trim((string)input('to', ''));
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (o.order_no LIKE :q1 OR s.name LIKE :q2 OR w.name LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }
        if ($categoryId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM inbound_items ii2 JOIN products p2 ON ii2.product_id = p2.id
                       WHERE ii2.order_id = o.id AND p2.category_id = :cid)';
            $params['cid'] = $categoryId;
        }
        if ($from !== '') {
            $where .= ' AND o.inbound_date >= :df';
            $params['df'] = $from;
        }
        if ($to !== '') {
            $where .= ' AND o.inbound_date <= :dt';
            $params['dt'] = $to;
        }
        // ---- 状态筛选：0待入库 / 1已入库 ----
        $statusFilter = trim((string)input('status', ''));
        if (in_array($statusFilter, ['0', '1'], true)) {
            $where .= ' AND o.status = :st';
            $params['st'] = (int)$statusFilter;
        }

        $goodsCols = "(SELECT GROUP_CONCAT(DISTINCT p3.name SEPARATOR '、') FROM inbound_items ii3 JOIN products p3 ON ii3.product_id = p3.id WHERE ii3.order_id = o.id) AS goods_names,
                      (SELECT GROUP_CONCAT(DISTINCT p3.spec SEPARATOR '、') FROM inbound_items ii3 JOIN products p3 ON ii3.product_id = p3.id WHERE ii3.order_id = o.id) AS goods_specs,
                      (SELECT COALESCE(SUM(ii4.quantity), 0) FROM inbound_items ii4 WHERE ii4.order_id = o.id) AS total_qty,
                      (SELECT COALESCE(SUM(ri.quantity),0) FROM return_orders ro JOIN return_items ri ON ri.order_id=ro.id WHERE ro.source_id=o.id AND ro.return_type=2 AND ro.status=0) AS return_pending_qty,
                      (SELECT COALESCE(SUM(ri.quantity),0) FROM return_orders ro JOIN return_items ri ON ri.order_id=ro.id WHERE ro.source_id=o.id AND ro.return_type=2 AND ro.status=1) AS return_done_qty";

        $countSql = "SELECT COUNT(*) FROM inbound_orders o
                     LEFT JOIN warehouses w ON o.warehouse_id = w.id
                     LEFT JOIN suppliers s ON o.supplier_id = s.id
                     WHERE $where";
        $listSql  = "SELECT o.id, o.order_no, o.status, o.inbound_date, o.total_amount, o.operator, o.remark,
                            o.production_id,
                            w.name AS warehouse_name, s.name AS supplier_name, $goodsCols
                     FROM inbound_orders o
                     LEFT JOIN warehouses w ON o.warehouse_id = w.id
                     LEFT JOIN suppliers s ON o.supplier_id = s.id
                     WHERE $where ORDER BY o.inbound_date DESC, o.id DESC";
        $page = $this->listData($countSql, $listSql, $params, 15);
        $page['status'] = $statusFilter;
        $page['search'] = $search;
        $page['category_id'] = $categoryId;
        $page['from'] = $from;
        $page['to'] = $to;

        $this->view('inbound/index', [
            'title'     => '入库管理',
            'page'      => $page,
            'warehouses'  => $this->db->fetchAll("SELECT id, name FROM warehouses WHERE status=1 ORDER BY name"),
            'suppliers'   => $this->db->fetchAll("SELECT id, name FROM suppliers WHERE status=1 ORDER BY name"),
            'products'    => $this->db->fetchAll("SELECT id, code, name, spec, unit, cost FROM products WHERE status=1 AND is_producible=0 ORDER BY name"),
            'categories'  => $this->db->fetchAll("SELECT id, name FROM product_categories ORDER BY sort ASC, id ASC"),
            'canCreate' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'create'),
            'canEdit'   => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'edit'),
            'canOperate' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'operate'),
            'canReturn'  => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'return'),
            'canDelete' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'delete'),
            'canPrint'  => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'print'),
        ]);
    }

    /** 取单据明细（AJAX，编辑时使用） */
    public function edit(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT * FROM inbound_orders WHERE id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('记录不存在');
        }
        $items = $this->db->fetchAll(
            "SELECT i.*, p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
             FROM inbound_items i
             LEFT JOIN products p ON i.product_id = p.id
             WHERE i.order_id=:id",
            ['id' => $id]
        );
        $this->json(['order' => $order, 'items' => $items]);
    }

    /** 新增（AJAX） */
    public function store(): void
    {
        $data = $this->collectOrderInput();
        $errors = $this->validateOrder($data, false);
        if ($errors) {
            $this->fail(implode('；', $errors));
        }

        try {
            $this->db->transaction(function ($db) use ($data) {
                $orderNo = gen_order_no('IN');
                $orderId = $db->insert('inbound_orders', [
                    'order_no'     => $orderNo,
                    'warehouse_id' => $data['warehouse_id'],
                    'supplier_id' => $data['supplier_id'],
                    'operator'     => $data['operator'],
                    'total_amount' => $data['total_amount'],
                    'inbound_date' => $data['inbound_date'],
                    'status'       => $data['status'],
                    'remark'       => $data['remark'],
                ]);

                foreach ($data['items'] as $row) {
                    $db->insert('inbound_items', [
                        'order_id'   => $orderId,
                        'product_id' => $row['product_id'],
                        'quantity'   => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'amount'      => $row['amount'],
                        'remark'      => $row['remark'],
                    ]);
                    // 已入库单（status=1）才更新库存
                    if ((int)$data['status'] === 1) {
                        $this->adjustInventory($db, $data['warehouse_id'], $row['product_id'], (float)$row['quantity']);
                    }
                }
            });
            $this->ok('添加成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 更新（AJAX） */
    public function update(): void
    {
        $id = (int)input('id', 0);
        $exists = $this->db->fetch("SELECT id, status, warehouse_id, production_id FROM inbound_orders WHERE id=:id", ['id' => $id]);
        if (!$exists) {
            $this->fail('记录不存在');
        }
        // 生产完工入库单由生产模块随任务统一维护
        if ((int)$exists['production_id'] > 0) {
            $this->fail('该单为生产完工入库单，请在生产管理中编辑对应生产任务');
        }

        $data = $this->collectOrderInput();
        $errors = $this->validateOrder($data, true, $id);
        if ($errors) {
            $this->fail(implode('；', $errors));
        }

        try {
            $this->db->transaction(function ($db) use ($id, $exists, $data) {
                // 如果原单据已入库，先回滚旧库存
                if ((int)$exists['status'] === 1) {
                    $oldItems = $db->fetchAll("SELECT product_id, quantity FROM inbound_items WHERE order_id=:id", ['id' => $id]);
                    foreach ($oldItems as $oi) {
                        $this->adjustInventory($db, $exists['warehouse_id'] ?? 0, $oi['product_id'], -(float)$oi['quantity']);
                    }
                }
                // 删除原明细
                $db->delete('inbound_items', 'order_id = :id', ['id' => $id]);
                // 更新主表
                $db->update('inbound_orders', [
                    'warehouse_id' => $data['warehouse_id'],
                    'supplier_id'  => $data['supplier_id'],
                    'operator'     => $data['operator'],
                    'total_amount' => $data['total_amount'],
                    'inbound_date' => $data['inbound_date'],
                    'status'       => $data['status'],
                    'remark'       => $data['remark'],
                ], 'id = :id', ['id' => $id]);

                // 写入新明细
                foreach ($data['items'] as $row) {
                    $db->insert('inbound_items', [
                        'order_id'   => $id,
                        'product_id' => $row['product_id'],
                        'quantity'   => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'amount'      => $row['amount'],
                        'remark'      => $row['remark'],
                    ]);
                    if ((int)$data['status'] === 1) {
                        $this->adjustInventory($db, $data['warehouse_id'], $row['product_id'], (float)$row['quantity']);
                    }
                }
            });
            $this->ok('编辑成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 删除（AJAX） */
    public function destroy(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch("SELECT id, status, warehouse_id, production_id FROM inbound_orders WHERE id=:id", ['id' => $id]);
        if (!$order) {
            $this->fail('记录不存在');
        }
        if ((int)$order['production_id'] > 0) {
            $this->fail('该单为生产完工入库单，请在生产管理中删除对应生产任务');
        }
        try {
            $this->db->transaction(function ($db) use ($id, $order) {
                // 已入库单删除需回滚库存
                if ((int)$order['status'] === 1) {
                    $items = $db->fetchAll("SELECT product_id, quantity FROM inbound_items WHERE order_id=:id", ['id' => $id]);
                    foreach ($items as $row) {
                        $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$row['product_id'], -(float)$row['quantity']);
                    }
                }
                $db->delete('inbound_items', 'order_id = :id', ['id' => $id]);
                $db->delete('inbound_orders', 'id = :id', ['id' => $id]);
            });
            $this->ok('删除成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 待入库单「已完成」（AJAX）：状态 0→1，同一事务内实际增加库存 */
    public function complete(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT id, status, warehouse_id, production_id FROM inbound_orders WHERE id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('记录不存在');
        }
        if ((int)$order['production_id'] > 0) {
            $this->fail('该单为生产完工入库单，由生产任务完工自动完成');
        }
        if ((int)$order['status'] !== 0) {
            $this->fail('该单据不是待入库状态，无需完成');
        }
        try {
            $this->db->transaction(function ($db) use ($id, $order) {
                $items = $db->fetchAll("SELECT product_id, quantity FROM inbound_items WHERE order_id=:id", ['id' => $id]);
                if (!$items) {
                    throw new \RuntimeException('入库单没有明细，无法完成');
                }
                foreach ($items as $row) {
                    $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$row['product_id'], (float)$row['quantity']);
                }
                $db->update('inbound_orders', ['status' => 1], 'id = :id', ['id' => $id]);
            });
            $this->ok('入库完成，库存已更新');
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    // ================= 入库退回（退回给供应商） =================

    /** 取入库单明细及可退余量（AJAX，退回弹窗使用）；生产入库单禁止退回 */
    public function returnItems(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT o.*, w.name AS warehouse_name, s.name AS supplier_name
             FROM inbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN suppliers s ON o.supplier_id = s.id
             WHERE o.id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('入库单不存在');
        }
        if ((int)$order['production_id'] > 0) {
            $this->fail('生产完工入库单不能直接退回，请在生产管理中处理');
        }
        if ((int)$order['status'] !== 1) {
            $this->fail('仅已入库单据可以发起退回');
        }
        $items = $this->db->fetchAll(
            "SELECT ii.id AS item_id, ii.product_id, ii.quantity, ii.unit_price,
                    p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit,
                    COALESCE((SELECT SUM(ri.quantity) FROM return_items ri
                              JOIN return_orders ro ON ro.id = ri.order_id
                              WHERE ro.source_id = :oid AND ro.return_type = 2 AND ri.product_id = ii.product_id), 0) AS returned_qty
             FROM inbound_items ii
             LEFT JOIN products p ON ii.product_id = p.id
             WHERE ii.order_id = :oid2",
            ['oid' => $id, 'oid2' => $id]
        );
        foreach ($items as &$it) {
            $it['remain_qty'] = round((float)$it['quantity'] - (float)$it['returned_qty'], 2);
        }
        unset($it);
        $this->json(['success' => true, 'order' => $order, 'items' => $items]);
    }

    /** 登记入库退回（AJAX）：生成「待退回」退回单，不动库存；数量不得超过本单可退余量 */
    public function returnStore(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT * FROM inbound_orders WHERE id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('入库单不存在');
        }
        if ((int)$order['production_id'] > 0) {
            $this->fail('生产完工入库单不能直接退回，请在生产管理中处理');
        }
        if ((int)$order['status'] !== 1) {
            $this->fail('仅已入库单据可以发起退回');
        }

        // 入参：items[入库明细ID] = 退回数量
        $posted = $_POST['items'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }
        $remark = trim((string)input('remark', ''));

        try {
            $returnId = 0;
            $this->db->transaction(function ($db) use ($id, $order, $posted, $remark, &$returnId) {
                $sourceItems = $db->fetchAll(
                    "SELECT ii.id, ii.product_id, ii.quantity, ii.unit_price,
                            COALESCE((SELECT SUM(ri.quantity) FROM return_items ri
                                      JOIN return_orders ro ON ro.id = ri.order_id
                                      WHERE ro.source_id = :oid AND ro.return_type = 2 AND ri.product_id = ii.product_id), 0) AS returned_qty
                     FROM inbound_items ii WHERE ii.order_id = :oid2",
                    ['oid' => $id, 'oid2' => $id]
                );
                $map = [];
                foreach ($sourceItems as $si) {
                    $map[(int)$si['id']] = $si;
                }
                $rows = [];
                $totalAmount = 0.0;
                foreach ($posted as $itemId => $qty) {
                    $itemId = (int)$itemId;
                    $qty = round((float)$qty, 2);
                    if ($qty <= 0) {
                        continue;
                    }
                    if (!isset($map[$itemId])) {
                        throw new \RuntimeException('存在不属于该入库单的明细，请刷新后重试');
                    }
                    $si = $map[$itemId];
                    $remain = round((float)$si['quantity'] - (float)$si['returned_qty'], 2);
                    if ($qty > $remain + 0.00001) {
                        $p = $db->fetch("SELECT name FROM products WHERE id = :id", ['id' => $si['product_id']]);
                        throw new \RuntimeException('商品「' . ($p['name'] ?? $si['product_id']) . '」退回数量 ' . $qty . ' 超过可退数量 ' . $remain);
                    }
                    $amount = round($qty * (float)$si['unit_price'], 2);
                    $rows[] = [
                        'product_id' => (int)$si['product_id'],
                        'quantity'   => $qty,
                        'unit_price' => (float)$si['unit_price'],
                        'amount'     => $amount,
                    ];
                    $totalAmount += $amount;
                }
                if (!$rows) {
                    throw new \RuntimeException('请填写至少一条退回数量');
                }
                $returnNo = gen_order_no('RTN');
                $returnId = (int)$db->insert('return_orders', [
                    'order_no'     => $returnNo,
                    'return_type'  => 2,
                    'source_id'    => $id,
                    'warehouse_id' => (int)$order['warehouse_id'],
                    'customer_id'  => 0,
                    'supplier_id'  => (int)$order['supplier_id'],
                    'operator'     => Auth::instance()->name(),
                    'total_amount' => round($totalAmount, 2),
                    'return_date'  => date('Y-m-d'),
                    'status'       => 0,
                    'remark'       => $remark !== '' ? ('入库退回：' . $order['order_no'] . '；' . $remark) : ('入库退回：' . $order['order_no']),
                ]);
                foreach ($rows as $r) {
                    $db->insert('return_items', [
                        'order_id'   => $returnId,
                        'product_id' => $r['product_id'],
                        'quantity'   => $r['quantity'],
                        'unit_price' => $r['unit_price'],
                        'amount'     => $r['amount'],
                        'remark'     => '入库退回：' . $order['order_no'],
                    ]);
                }
            });
            $this->ok('已登记退回（待退回），请到退回管理中点「已完成」实际扣减库存', ['id' => $returnId]);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 打印（支持分类/日期筛选与 ids 指定记录） */
    public function print(): void
    {
        $search = trim((string)input('q', ''));
        $categoryId = (int)input('category_id', 0);
        $from = trim((string)input('from', ''));
        $to = trim((string)input('to', ''));
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (o.order_no LIKE :q1 OR s.name LIKE :q2 OR w.name LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }
        if ($categoryId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM inbound_items ii2 JOIN products p2 ON ii2.product_id = p2.id
                       WHERE ii2.order_id = o.id AND p2.category_id = :cid)';
            $params['cid'] = $categoryId;
        }
        if ($from !== '') {
            $where .= ' AND o.inbound_date >= :df';
            $params['df'] = $from;
        }
        if ($to !== '') {
            $where .= ' AND o.inbound_date <= :dt';
            $params['dt'] = $to;
        }
        $idsRaw = trim((string)input('ids', ''));
        if ($idsRaw !== '') {
            $idList = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn ($v) => $v > 0));
            if ($idList) {
                $where .= ' AND o.id IN (' . implode(',', $idList) . ')';
            }
        }
        $orders = $this->db->fetchAll(
            "SELECT o.*, w.name AS warehouse_name, s.name AS supplier_name
             FROM inbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN suppliers s ON o.supplier_id = s.id
             WHERE $where ORDER BY o.id DESC",
            $params
        );

        // 明细
        $itemsByOrder = [];
        if ($orders) {
            $ids = array_column($orders, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT i.order_id, i.product_id, i.quantity, i.unit_price, i.amount, i.remark,
                           p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
                    FROM inbound_items i
                    LEFT JOIN products p ON i.product_id = p.id
                    WHERE i.order_id IN ($in)";
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $itemsByOrder[$row['order_id']][] = $row;
            }
        }

        echo $this->render('inbound/print', [
            'title'         => '入库单',
            'orders'        => $orders,
            'itemsByOrder'  => $itemsByOrder,
            'siteName'      => $this->siteName(),
            'printer'       => Auth::instance()->name(), // 经办人显示当前打印操作人
        ]);
    }

    /** 打印单张入库单（含明细） */
    public function detail(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT o.*, w.name AS warehouse_name, s.name AS supplier_name
             FROM inbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN suppliers s ON o.supplier_id = s.id
             WHERE o.id=:id",
            ['id' => $id]
        );
        if (!$order) {
            echo '<div style="padding:30px;text-align:center;color:#999">入库单不存在</div>';
            return;
        }
        $items = $this->db->fetchAll(
            "SELECT i.*, p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
             FROM inbound_items i
             LEFT JOIN products p ON i.product_id = p.id
             WHERE i.order_id=:id",
            ['id' => $id]
        );
        echo $this->render('inbound/print', [
            'title'         => '入库单详情',
            'orders'        => [$order],
            'itemsByOrder'  => [$id => $items],
            'siteName'      => $this->siteName(),
            'printer'       => Auth::instance()->name(), // 经办人显示当前打印操作人
        ]);
    }

    // ---------- 内部 ----------

    /** 同步库存：增量调整 warehouse_id + product_id 维度库存 */
    private function adjustInventory($db, int $warehouseId, int $productId, float $delta): void
    {
        if ($warehouseId <= 0 || $productId <= 0) {
            return;
        }
        $exists = $db->fetch(
            "SELECT id, quantity FROM inventory WHERE warehouse_id=:w AND product_id=:p",
            ['w' => $warehouseId, 'p' => $productId]
        );
        if ($exists) {
            $newQty = (float)$exists['quantity'] + $delta;
            $db->update('inventory', ['quantity' => $newQty], 'id = :id', ['id' => $exists['id']]);
        } elseif ($delta > 0) {
            $db->insert('inventory', [
                'warehouse_id' => $warehouseId,
                'product_id'   => $productId,
                'quantity'     => $delta,
            ]);
        }
    }

    /** 收集主表 + 明细输入 */
    private function collectOrderInput(): array
    {
        $items = $_POST['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        $cleanItems = [];
        foreach ($items as $row) {
            $pid   = (int)($row['product_id'] ?? 0);
            $qty   = (float)($row['quantity'] ?? 0);
            $price = (float)($row['unit_price'] ?? 0);
            $amount = round($qty * $price, 2);
            $remark = trim((string)($row['remark'] ?? ''));
            if ($pid > 0 && $qty > 0) {
                $cleanItems[] = [
                    'product_id' => $pid,
                    'quantity'   => $qty,
                    'unit_price' => $price,
                    'amount'      => $amount,
                    'remark'      => $remark,
                ];
            }
        }

        return [
            'warehouse_id' => (int)input('warehouse_id', 0),
            'supplier_id'  => (int)input('supplier_id', 0),
            // 经办人固定为当前登录操作人，不可由前端指定
            'operator'     => \Core\Auth::instance()->name(),
            'inbound_date' => trim((string)input('inbound_date', date('Y-m-d'))),
            'status'       => (int)input('status', 0) === 1 ? 1 : 0,
            'remark'       => trim((string)input('remark', '')),
            'total_amount' => (float)input('total_amount', 0),
            'items'        => $cleanItems,
        ];
    }

    /** 校验主表 + 明细 */
    private function validateOrder(array $data, bool $isUpdate, int $id = 0): array
    {
        $errors = [];
        if ($data['warehouse_id'] <= 0) {
            $errors[] = '请选择入库仓库';
        }
        if ($data['supplier_id'] <= 0) {
            $errors[] = '请选择供应商';
        }
        if ($data['inbound_date'] === '') {
            $errors[] = '请选择入库日期';
        }
        if (empty($data['items'])) {
            $errors[] = '至少录入一条明细';
        }
        return $errors;
    }

    private function siteName(): string
    {
        $s = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'site_name'");
        return $s['value'] ?? '工厂仓库 ERP 管理系统';
    }
}
