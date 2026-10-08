<?php
/**
 * 出库管理
 * - 列表 / 新增 / 编辑 / 删除 / 打印
 * - 主表 + 明细行（商品 + 数量 + 单价 + 金额）
 * - 出库时同步扣减 inventory（warehouse_id + product_id -= quantity）
 * - 扣减后库存为负则抛错回滚
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;
use Core\Csrf;

final class OutboundController extends BaseController
{
    protected string $module = 'outbound';

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
            $where .= ' AND (o.order_no LIKE :q1 OR c.name LIKE :q2 OR w.name LIKE :q3 OR o.remark LIKE :q4)';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }
        if ($categoryId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM outbound_items oi2 JOIN products p2 ON oi2.product_id = p2.id
                       WHERE oi2.order_id = o.id AND p2.category_id = :cid)';
            $params['cid'] = $categoryId;
        }
        if ($from !== '') {
            $where .= ' AND o.outbound_date >= :df';
            $params['df'] = $from;
        }
        if ($to !== '') {
            $where .= ' AND o.outbound_date <= :dt';
            $params['dt'] = $to;
        }
        // ---- 状态筛选：0待出库 / 1已出库 ----
        $statusFilter = trim((string)input('status', ''));
        if (in_array($statusFilter, ['0', '1'], true)) {
            $where .= ' AND o.status = :st';
            $params['st'] = (int)$statusFilter;
        }

        $goodsCols = "(SELECT GROUP_CONCAT(DISTINCT p3.name SEPARATOR '、') FROM outbound_items oi3 JOIN products p3 ON oi3.product_id = p3.id WHERE oi3.order_id = o.id) AS goods_names,
                      (SELECT GROUP_CONCAT(DISTINCT p3.spec SEPARATOR '、') FROM outbound_items oi3 JOIN products p3 ON oi3.product_id = p3.id WHERE oi3.order_id = o.id) AS goods_specs,
                      (SELECT COALESCE(SUM(oi4.quantity), 0) FROM outbound_items oi4 WHERE oi4.order_id = o.id) AS total_qty,
                      (SELECT COALESCE(SUM(ri.quantity),0) FROM return_orders ro JOIN return_items ri ON ri.order_id=ro.id WHERE ro.source_id=o.id AND ro.return_type=1 AND ro.status=0) AS reverse_pending_qty,
                      (SELECT COALESCE(SUM(ri.quantity),0) FROM return_orders ro JOIN return_items ri ON ri.order_id=ro.id WHERE ro.source_id=o.id AND ro.return_type=1 AND ro.status=1) AS reverse_done_qty";

        $countSql = "SELECT COUNT(*) FROM outbound_orders o
                     LEFT JOIN warehouses w ON o.warehouse_id = w.id
                     LEFT JOIN customers c ON o.customer_id = c.id
                     WHERE $where";
        $listSql  = "SELECT o.id, o.order_no, o.status, o.outbound_date, o.total_amount, o.operator, o.remark,
                            o.production_id,
                            w.name AS warehouse_name, c.name AS customer_name, $goodsCols
                     FROM outbound_orders o
                     LEFT JOIN warehouses w ON o.warehouse_id = w.id
                     LEFT JOIN customers c ON o.customer_id = c.id
                     WHERE $where ORDER BY o.outbound_date DESC, o.id DESC";
        $page = $this->listData($countSql, $listSql, $params, 15);
        $page['status'] = $statusFilter;
        $page['search'] = $search;
        $page['category_id'] = $categoryId;
        $page['from'] = $from;
        $page['to'] = $to;

        $this->view('outbound/index', [
            'title'     => '出库管理',
            'page'      => $page,
            'warehouses'  => $this->db->fetchAll("SELECT id, name FROM warehouses WHERE status=1 ORDER BY name"),
            'customers'   => $this->db->fetchAll("SELECT id, name FROM customers WHERE status=1 ORDER BY name"),
            'products'    => $this->db->fetchAll("SELECT id, code, name, spec, unit, price,
                                                         (SELECT COALESCE(SUM(i.quantity), 0) FROM inventory i WHERE i.product_id = products.id) AS stock_total
                                                  FROM products WHERE status=1 ORDER BY name"),
            'categories'  => $this->db->fetchAll("SELECT id, name FROM product_categories ORDER BY sort ASC, id ASC"),
            'canCreate' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'create'),
            'canEdit'   => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'edit'),
            'canOperate' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'operate'),
            'canReverse' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'reverse'),
            'canDelete' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'delete'),
            'canPrint'  => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'print'),
        ]);
    }

    /** 取单据明细（AJAX，编辑时使用） */
    public function edit(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT * FROM outbound_orders WHERE id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('记录不存在');
        }
        $items = $this->db->fetchAll(
            "SELECT i.*, p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
             FROM outbound_items i
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
        // 已出库（status=1）先做库存预检
        if ((int)$data['status'] === 1) {
            $stockErr = $this->checkStock($data['warehouse_id'], $data['items']);
            if ($stockErr) {
                $this->fail('库存不足：' . implode('，', $stockErr));
            }
        }

        try {
            $this->db->transaction(function ($db) use ($data) {
                $orderNo = gen_order_no('OUT');
                $orderId = $db->insert('outbound_orders', [
                    'order_no'     => $orderNo,
                    'warehouse_id' => $data['warehouse_id'],
                    'customer_id'  => $data['customer_id'],
                    'operator'     => $data['operator'],
                    'total_amount' => $data['total_amount'],
                    'outbound_date' => $data['outbound_date'],
                    'status'       => $data['status'],
                    'remark'       => $data['remark'],
                ]);

                foreach ($data['items'] as $row) {
                    $db->insert('outbound_items', [
                        'order_id'   => $orderId,
                        'product_id' => $row['product_id'],
                        'quantity'   => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'amount'      => $row['amount'],
                        'remark'      => $row['remark'],
                    ]);
                    if ((int)$data['status'] === 1) {
                        $this->adjustInventory($db, $data['warehouse_id'], $row['product_id'], -(float)$row['quantity']);
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
        $exists = $this->db->fetch("SELECT id, status, warehouse_id, production_id FROM outbound_orders WHERE id=:id", ['id' => $id]);
        if (!$exists) {
            $this->fail('记录不存在');
        }
        // 生产领料出库单由生产模块统一维护（编辑会重新生成），出库模块只可查看
        if ((int)$exists['production_id'] > 0) {
            $this->fail('该单为生产领料出库单，请在生产管理中编辑对应生产任务');
        }

        $data = $this->collectOrderInput();
        $errors = $this->validateOrder($data, true, $id);
        if ($errors) {
            $this->fail(implode('；', $errors));
        }
        // 已出库（status=1）时库存预检：先把旧库存退回再检查新需求
        if ((int)$data['status'] === 1) {
            $stockErr = $this->checkStockForUpdate($data['warehouse_id'], $data['items'], $id);
            if ($stockErr) {
                $this->fail('库存不足：' . implode('，', $stockErr));
            }
        }

        try {
            $this->db->transaction(function ($db) use ($id, $exists, $data) {
                // 如果原单据已出库，先回滚（加回）旧库存
                if ((int)$exists['status'] === 1) {
                    $oldItems = $db->fetchAll("SELECT product_id, quantity FROM outbound_items WHERE order_id=:id", ['id' => $id]);
                    foreach ($oldItems as $oi) {
                        $this->adjustInventory($db, (int)$exists['warehouse_id'], (int)$oi['product_id'], (float)$oi['quantity']);
                    }
                }
                $db->delete('outbound_items', 'order_id = :id', ['id' => $id]);
                $db->update('outbound_orders', [
                    'warehouse_id' => $data['warehouse_id'],
                    'customer_id'  => $data['customer_id'],
                    'operator'     => $data['operator'],
                    'total_amount' => $data['total_amount'],
                    'outbound_date' => $data['outbound_date'],
                    'status'       => $data['status'],
                    'remark'       => $data['remark'],
                ], 'id = :id', ['id' => $id]);

                foreach ($data['items'] as $row) {
                    $db->insert('outbound_items', [
                        'order_id'   => $id,
                        'product_id' => $row['product_id'],
                        'quantity'   => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'amount'      => $row['amount'],
                        'remark'      => $row['remark'],
                    ]);
                    if ((int)$data['status'] === 1) {
                        $this->adjustInventory($db, $data['warehouse_id'], $row['product_id'], -(float)$row['quantity']);
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
        $order = $this->db->fetch("SELECT id, status, warehouse_id, production_id FROM outbound_orders WHERE id=:id", ['id' => $id]);
        if (!$order) {
            $this->fail('记录不存在');
        }
        // 生产领料出库单随生产任务联动，禁止在出库模块直接删除
        if ((int)$order['production_id'] > 0) {
            $this->fail('该单为生产领料出库单，请在生产管理中删除对应生产任务');
        }
        try {
            $this->db->transaction(function ($db) use ($id, $order) {
                // 已出库单删除需回滚（加回）库存
                if ((int)$order['status'] === 1) {
                    $items = $db->fetchAll("SELECT product_id, quantity FROM outbound_items WHERE order_id=:id", ['id' => $id]);
                    foreach ($items as $row) {
                        $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$row['product_id'], (float)$row['quantity']);
                    }
                }
                $db->delete('outbound_items', 'order_id = :id', ['id' => $id]);
                $db->delete('outbound_orders', 'id = :id', ['id' => $id]);
            });
            $this->ok('删除成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 待出库单「已完成」（AJAX）：状态 0→1，先预检库存，同一事务内实际扣减 */
    public function complete(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT id, status, warehouse_id, production_id FROM outbound_orders WHERE id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('记录不存在');
        }
        if ((int)$order['production_id'] > 0) {
            $this->fail('该单为生产领料出库单，由生产任务完工自动完成');
        }
        if ((int)$order['status'] !== 0) {
            $this->fail('该单据不是待出库状态，无需完成');
        }
        $items = $this->db->fetchAll("SELECT product_id, quantity FROM outbound_items WHERE order_id=:id", ['id' => $id]);
        if (!$items) {
            $this->fail('出库单没有明细，无法完成');
        }
        // 完成前库存预检
        $checkItems = array_map(static fn ($r) => ['product_id' => (int)$r['product_id'], 'quantity' => (float)$r['quantity']], $items);
        $stockErr = $this->checkStock((int)$order['warehouse_id'], $checkItems);
        if ($stockErr) {
            $this->fail('库存不足：' . implode('，', $stockErr));
        }
        try {
            $this->db->transaction(function ($db) use ($id, $order, $items) {
                foreach ($items as $row) {
                    $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$row['product_id'], -(float)$row['quantity']);
                }
                $db->update('outbound_orders', ['status' => 1], 'id = :id', ['id' => $id]);
            });
            $this->ok('出库完成，库存已扣减');
        } catch (\Throwable $e) {
            $this->fail($e instanceof \PDOException ? '操作失败，请重试' : $e->getMessage());
        }
    }

    // ================= 出库反回（客户退货入仓） =================

    /** 取出库单明细及可反回余量（AJAX，反回弹窗使用）；生产领料单禁止反回 */
    public function reverseItems(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT o.*, w.name AS warehouse_name, c.name AS customer_name
             FROM outbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN customers c ON o.customer_id = c.id
             WHERE o.id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('出库单不存在');
        }
        if ((int)$order['production_id'] > 0) {
            $this->fail('生产领料出库单不能直接反回，请在生产管理中处理');
        }
        if ((int)$order['status'] !== 1) {
            $this->fail('仅已出库单据可以发起反回');
        }
        $items = $this->db->fetchAll(
            "SELECT oi.id AS item_id, oi.product_id, oi.quantity, oi.unit_price,
                    p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit,
                    COALESCE((SELECT SUM(ri.quantity) FROM return_items ri
                              JOIN return_orders ro ON ro.id = ri.order_id
                              WHERE ro.source_id = :oid AND ro.return_type = 1 AND ri.product_id = oi.product_id), 0) AS returned_qty
             FROM outbound_items oi
             LEFT JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id = :oid2",
            ['oid' => $id, 'oid2' => $id]
        );
        foreach ($items as &$it) {
            $it['remain_qty'] = round((float)$it['quantity'] - (float)$it['returned_qty'], 2);
        }
        unset($it);
        $this->json(['success' => true, 'order' => $order, 'items' => $items]);
    }

    /** 登记出库反回（AJAX）：生成「待反回」退回单，不动库存；数量不得超过本单可反回余量 */
    public function reverseStore(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch("SELECT * FROM outbound_orders WHERE id=:id", ['id' => $id]);
        if (!$order) {
            $this->fail('出库单不存在');
        }
        if ((int)$order['production_id'] > 0) {
            $this->fail('生产领料出库单不能直接反回，请在生产管理中处理');
        }
        if ((int)$order['status'] !== 1) {
            $this->fail('仅已出库单据可以发起反回');
        }

        $posted = $_POST['items'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }
        $remark = trim((string)input('remark', ''));

        try {
            $returnId = 0;
            $this->db->transaction(function ($db) use ($id, $order, $posted, $remark, &$returnId) {
                $sourceItems = $db->fetchAll(
                    "SELECT oi.id, oi.product_id, oi.quantity, oi.unit_price,
                            COALESCE((SELECT SUM(ri.quantity) FROM return_items ri
                                      JOIN return_orders ro ON ro.id = ri.order_id
                                      WHERE ro.source_id = :oid AND ro.return_type = 1 AND ri.product_id = oi.product_id), 0) AS returned_qty
                     FROM outbound_items oi WHERE oi.order_id = :oid2",
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
                        throw new \RuntimeException('存在不属于该出库单的明细，请刷新后重试');
                    }
                    $si = $map[$itemId];
                    $remain = round((float)$si['quantity'] - (float)$si['returned_qty'], 2);
                    if ($qty > $remain + 0.00001) {
                        $p = $db->fetch("SELECT name FROM products WHERE id = :id", ['id' => $si['product_id']]);
                        throw new \RuntimeException('商品「' . ($p['name'] ?? $si['product_id']) . '」反回数量 ' . $qty . ' 超过可反回数量 ' . $remain);
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
                    throw new \RuntimeException('请填写至少一条反回数量');
                }
                $returnNo = gen_order_no('RTN');
                $returnId = (int)$db->insert('return_orders', [
                    'order_no'     => $returnNo,
                    'return_type'  => 1,
                    'source_id'    => $id,
                    'warehouse_id' => (int)$order['warehouse_id'],
                    'customer_id'  => (int)$order['customer_id'],
                    'supplier_id'  => 0,
                    'operator'     => Auth::instance()->name(),
                    'total_amount' => round($totalAmount, 2),
                    'return_date'  => date('Y-m-d'),
                    'status'       => 0,
                    'remark'       => $remark !== '' ? ('出库反回：' . $order['order_no'] . '；' . $remark) : ('出库反回：' . $order['order_no']),
                ]);
                foreach ($rows as $r) {
                    $db->insert('return_items', [
                        'order_id'   => $returnId,
                        'product_id' => $r['product_id'],
                        'quantity'   => $r['quantity'],
                        'unit_price' => $r['unit_price'],
                        'amount'     => $r['amount'],
                        'remark'     => '出库反回：' . $order['order_no'],
                    ]);
                }
            });
            $this->ok('已登记反回（待反回），请到退回管理中点「已完成」实际加回库存', ['id' => $returnId]);
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 打印（多张列表，支持分类/日期筛选与 ids 指定记录） */
    public function print(): void
    {
        $search = trim((string)input('q', ''));
        $categoryId = (int)input('category_id', 0);
        $from = trim((string)input('from', ''));
        $to = trim((string)input('to', ''));
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (o.order_no LIKE :q1 OR c.name LIKE :q2 OR w.name LIKE :q3 OR o.remark LIKE :q4)';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }
        if ($categoryId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM outbound_items oi2 JOIN products p2 ON oi2.product_id = p2.id
                       WHERE oi2.order_id = o.id AND p2.category_id = :cid)';
            $params['cid'] = $categoryId;
        }
        if ($from !== '') {
            $where .= ' AND o.outbound_date >= :df';
            $params['df'] = $from;
        }
        if ($to !== '') {
            $where .= ' AND o.outbound_date <= :dt';
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
            "SELECT o.*, w.name AS warehouse_name, c.name AS customer_name
             FROM outbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN customers c ON o.customer_id = c.id
             WHERE $where ORDER BY o.id DESC",
            $params
        );

        $itemsByOrder = [];
        if ($orders) {
            $ids = array_column($orders, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $sql = "SELECT i.order_id, i.product_id, i.quantity, i.unit_price, i.amount, i.remark,
                           p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
                    FROM outbound_items i
                    LEFT JOIN products p ON i.product_id = p.id
                    WHERE i.order_id IN ($in)";
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $itemsByOrder[$row['order_id']][] = $row;
            }
        }

        echo $this->render('outbound/print', [
            'title'         => '出库单',
            'orders'        => $orders,
            'itemsByOrder'  => $itemsByOrder,
            'siteName'      => $this->siteName(),
            'printer'       => Auth::instance()->name(), // 经办人显示当前打印操作人
        ]);
    }

    /** 打印单张出库单 */
    public function detail(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT o.*, w.name AS warehouse_name, c.name AS customer_name
             FROM outbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN customers c ON o.customer_id = c.id
             WHERE o.id=:id",
            ['id' => $id]
        );
        if (!$order) {
            echo '<div style="padding:30px;text-align:center;color:#999">出库单不存在</div>';
            return;
        }
        $items = $this->db->fetchAll(
            "SELECT i.*, p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
             FROM outbound_items i
             LEFT JOIN products p ON i.product_id = p.id
             WHERE i.order_id=:id",
            ['id' => $id]
        );
        echo $this->render('outbound/print', [
            'title'         => '出库单详情',
            'orders'        => [$order],
            'itemsByOrder'  => [$id => $items],
            'siteName'      => $this->siteName(),
            'printer'       => Auth::instance()->name(), // 经办人显示当前打印操作人
        ]);
    }

    // ---------- 内部 ----------

    /** 同步库存：增量调整（delta 负值代表出库扣减） */
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
            if ($newQty < 0) {
                // 二次保护：理论上已预检，这里再硬性拦截
                throw new \RuntimeException('库存不足，无法出库');
            }
            $db->update('inventory', ['quantity' => $newQty], 'id = :id', ['id' => $exists['id']]);
        } elseif ($delta < 0) {
            throw new \RuntimeException('库存不足，无法出库');
        } elseif ($delta > 0) {
            $db->insert('inventory', [
                'warehouse_id' => $warehouseId,
                'product_id'   => $productId,
                'quantity'     => $delta,
            ]);
        }
    }

    /** 新增出库的库存预检 */
    private function checkStock(int $warehouseId, array $items): array
    {
        $errs = [];
        foreach ($items as $row) {
            $stock = (float)$this->db->fetchColumn(
                "SELECT quantity FROM inventory WHERE warehouse_id=:w AND product_id=:p",
                ['w' => $warehouseId, 'p' => $row['product_id']]
            );
            if ($stock < $row['quantity']) {
                $p = $this->db->fetch("SELECT name FROM products WHERE id=:id", ['id' => $row['product_id']]);
                $errs[] = ($p['name'] ?? '商品' . $row['product_id']) . '（需要 ' . $row['quantity'] . '，库存 ' . $stock . '）';
            }
        }
        return $errs;
    }

    /** 编辑出库的库存预检（先把旧出库量加回再检查） */
    private function checkStockForUpdate(int $warehouseId, array $newItems, int $orderId): array
    {
        // 获取旧明细（按 product_id 聚合）
        $oldRows = $this->db->fetchAll(
            "SELECT product_id, SUM(quantity) AS qty FROM outbound_items WHERE order_id=:id GROUP BY product_id",
            ['id' => $orderId]
        );
        $oldMap = [];
        foreach ($oldRows as $row) {
            $oldMap[(int)$row['product_id']] = (float)$row['qty'];
        }
        // 新明细按 product_id 聚合
        $newMap = [];
        foreach ($newItems as $row) {
            $pid = (int)$row['product_id'];
            $newMap[$pid] = ($newMap[$pid] ?? 0) + (float)$row['quantity'];
        }

        $errs = [];
        $allPids = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
        foreach ($allPids as $pid) {
            $need = ($newMap[$pid] ?? 0) - ($oldMap[$pid] ?? 0);
            if ($need <= 0) {
                continue; // 新需求不大于旧需求，肯定够
            }
            $stock = (float)$this->db->fetchColumn(
                "SELECT quantity FROM inventory WHERE warehouse_id=:w AND product_id=:p",
                ['w' => $warehouseId, 'p' => $pid]
            );
            if ($stock < $need) {
                $p = $this->db->fetch("SELECT name FROM products WHERE id=:id", ['id' => $pid]);
                $errs[] = ($p['name'] ?? '商品' . $pid) . '（需新增 ' . $need . '，库存 ' . $stock . '）';
            }
        }
        return $errs;
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
            'customer_id'  => (int)input('customer_id', 0),
            // 经办人固定为当前登录操作人，不可由前端指定
            'operator'     => \Core\Auth::instance()->name(),
            'outbound_date' => trim((string)input('outbound_date', date('Y-m-d'))),
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
            $errors[] = '请选择出库仓库';
        }
        if ($data['customer_id'] <= 0) {
            $errors[] = '请选择客户';
        }
        if ($data['outbound_date'] === '') {
            $errors[] = '请选择出库日期';
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
