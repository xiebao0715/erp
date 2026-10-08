<?php
/**
 * 退回管理（退回 / 反回待办中心）
 * - 不再支持手工新增独立退回单：入库退回由「入库管理」发起，出库反回由「出库管理」发起
 * - 主表 + 明细行（商品 + 数量 + 单价 + 金额）
 * - 类型：1出库反回（客户退货，确认后商品入回仓库，加库存）
 *         2入库退回（退回给供应商，确认后商品退出仓库，扣库存，库存不足则失败）
 * - 登记后为待办（待反回/待退回），不影响库存；列表点「已完成」才实际变动库存
 * - source_id 关联来源出库单/入库单，数量不得超过来源单余量
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;
use Core\Csrf;

final class ReturnController extends BaseController
{
    protected string $module = 'return';

    /** 退回单的「已完成」待办推进归入编辑权限（全局 complete 默认映射为 operate，此处覆盖） */
    protected array $actionMap = ['complete' => 'edit'];

    public const TYPE_OUT_RETURN = 1; // 出库反回：客户退货，商品入回仓库（加库存）
    public const TYPE_IN_RETURN  = 2; // 入库退回：退回给供应商，商品出仓库（扣库存）
    public const TYPE_NAMES = [1 => '出库反回', 2 => '入库退回'];

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
            $where .= ' AND (o.order_no LIKE :q1 OR w.name LIKE :q2 OR s.name LIKE :q3 OR c.name LIKE :q4)';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }
        if ($categoryId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM return_items ri2 JOIN products p2 ON ri2.product_id = p2.id
                       WHERE ri2.order_id = o.id AND p2.category_id = :cid)';
            $params['cid'] = $categoryId;
        }
        if ($from !== '') {
            $where .= ' AND o.return_date >= :df';
            $params['df'] = $from;
        }
        if ($to !== '') {
            $where .= ' AND o.return_date <= :dt';
            $params['dt'] = $to;
        }
        // 状态筛选：0待办（待退回/待反回） / 1已完成（已退回/已反回）
        $statusFilter = trim((string)input('status', ''));
        if (in_array($statusFilter, ['0', '1'], true)) {
            $where .= ' AND o.status = :st';
            $params['st'] = (int)$statusFilter;
        }
        // 类型筛选：1出库反回 / 2入库退回
        $typeFilter = trim((string)input('return_type', ''));
        if (in_array($typeFilter, ['1', '2'], true)) {
            $where .= ' AND o.return_type = :rt';
            $params['rt'] = (int)$typeFilter;
        }

        $goodsCols = "(SELECT GROUP_CONCAT(DISTINCT p3.name SEPARATOR '、') FROM return_items ri3 JOIN products p3 ON ri3.product_id = p3.id WHERE ri3.order_id = o.id) AS goods_names,
                      (SELECT GROUP_CONCAT(DISTINCT p3.spec SEPARATOR '、') FROM return_items ri3 JOIN products p3 ON ri3.product_id = p3.id WHERE ri3.order_id = o.id) AS goods_specs,
                      (SELECT COALESCE(SUM(ri4.quantity), 0) FROM return_items ri4 WHERE ri4.order_id = o.id) AS total_qty";

        $countSql = "SELECT COUNT(*) FROM return_orders o
                     LEFT JOIN warehouses w ON o.warehouse_id = w.id
                     LEFT JOIN suppliers s ON o.supplier_id = s.id
                     LEFT JOIN customers c ON o.customer_id = c.id
                     WHERE $where";
        $listSql = "SELECT o.id, o.order_no, o.return_type, o.source_id, o.production_cancel_id, o.status, o.return_date, o.total_amount, o.operator, o.remark,
                           w.name AS warehouse_name, s.name AS supplier_name, c.name AS customer_name,
                           pc.task_status AS cancel_task_status,
                           io.order_no AS inbound_no, oo.order_no AS outbound_no, $goodsCols
                    FROM return_orders o
                    LEFT JOIN warehouses w ON o.warehouse_id = w.id
                    LEFT JOIN suppliers s ON o.supplier_id = s.id
                    LEFT JOIN customers c ON o.customer_id = c.id
                    LEFT JOIN production_cancels pc ON o.production_cancel_id = pc.id
                    LEFT JOIN inbound_orders io ON o.return_type = 2 AND o.source_id = io.id
                    LEFT JOIN outbound_orders oo ON o.return_type = 1 AND o.source_id = oo.id
                    WHERE $where ORDER BY o.return_date DESC, o.id DESC";
        $page = $this->listData($countSql, $listSql, $params, 15);
        $page['status'] = $statusFilter;
        $page['return_type'] = $typeFilter;
        $page['search'] = $search;
        $page['category_id'] = $categoryId;
        $page['from'] = $from;
        $page['to'] = $to;

        $this->view('return/index', [
            'title'     => '退回管理',
            'page'      => $page,
            'warehouses' => $this->db->fetchAll("SELECT id, name FROM warehouses WHERE status=1 ORDER BY name"),
            'suppliers'  => $this->db->fetchAll("SELECT id, name FROM suppliers WHERE status=1 ORDER BY name"),
            'customers'  => $this->db->fetchAll("SELECT id, name FROM customers WHERE status=1 ORDER BY name"),
            'products'   => $this->db->fetchAll("SELECT id, code, name, spec, unit, price, cost,
                                                        (SELECT COALESCE(SUM(i.quantity), 0) FROM inventory i WHERE i.product_id = products.id) AS stock_total
                                                 FROM products WHERE status=1 ORDER BY name"),
            'categories' => $this->db->fetchAll("SELECT id, name FROM product_categories ORDER BY sort ASC, id ASC"),
            'typeNames'  => self::TYPE_NAMES,
            'canCreate' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'create'),
            'canEdit'   => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'edit'),
            'canDelete' => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'delete'),
            'canPrint'  => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'print'),
        ]);
    }

    /** 取单据明细（AJAX，编辑时使用） */
    public function edit(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT * FROM return_orders WHERE id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('记录不存在');
        }
        $items = $this->db->fetchAll(
            "SELECT i.*,
                    p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
             FROM return_items i
             LEFT JOIN products p ON i.product_id = p.id
             WHERE i.order_id=:id",
            ['id' => $id]
        );
        $this->json(['order' => $order, 'items' => $items]);
    }

    /** 新增（AJAX） */
    public function store(): void
    {
        Csrf::verify();
        $data = $this->collectOrderInput();
        $errors = $this->validateOrder($data);
        if ($errors) {
            $this->fail(implode('；', $errors));
        }
        // 入库退回（出仓方向）：仅「已完成」单才校验并扣减库存；待退回单不影响库存
        if ((int)$data['status'] === 1) {
            $stockErr = $this->checkStock($data['warehouse_id'], $data['items']);
            if ($stockErr !== '') {
                $this->fail($stockErr);
            }
        }

        try {
            $this->db->transaction(function ($db) use ($data) {
                $orderNo = gen_order_no('RTN');
                $orderId = $db->insert('return_orders', [
                    'order_no'     => $orderNo,
                    'return_type'  => $data['return_type'],
                    'warehouse_id' => $data['warehouse_id'],
                    'customer_id'  => $data['customer_id'],
                    'supplier_id'  => $data['supplier_id'],
                    'operator'     => $data['operator'],
                    'total_amount' => $data['total_amount'],
                    'return_date'  => $data['return_date'],
                    'status'       => $data['status'],
                    'remark'       => $data['remark'],
                ]);

                foreach ($data['items'] as $row) {
                    $db->insert('return_items', [
                        'order_id'     => $orderId,
                        'product_id'   => $row['product_id'],
                        'quantity'     => $row['quantity'],
                        'unit_price'   => $row['unit_price'],
                        'amount'       => $row['amount'],
                        'remark'       => $row['remark'],
                    ]);
                    if ((int)$data['status'] === 1) {
                        // 出库退回（客户退货）加库存；入库退回（退回供应商）扣库存
                        $delta = $data['return_type'] === self::TYPE_OUT_RETURN ? (float)$row['quantity'] : -(float)$row['quantity'];
                        $this->adjustInventory($db, $data['warehouse_id'], $row['product_id'], $delta);
                    }
                }
            });
            $this->ok('添加成功');
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 更新（AJAX） */
    public function update(): void
    {
        Csrf::verify();
        $id = (int)input('id', 0);
        $exists = $this->db->fetch("SELECT id, status, return_type, warehouse_id FROM return_orders WHERE id=:id", ['id' => $id]);
        if (!$exists) {
            $this->fail('记录不存在');
        }

        $data = $this->collectOrderInput();
        $errors = $this->validateOrder($data);
        if ($errors) {
            $this->fail(implode('；', $errors));
        }
        // 入库退回（出仓方向）库存校验：原单据已生效时，先把旧占用加回再比较增量
        if ((int)$data['status'] === 1 && (int)$data['return_type'] === self::TYPE_IN_RETURN) {
            $stockErr = $this->checkStockForUpdate($data['warehouse_id'], $data['items'], $id, (int)$exists['status'] === 1);
            if ($stockErr !== '') {
                $this->fail($stockErr);
            }
        }

        try {
            $this->db->transaction(function ($db) use ($id, $exists, $data) {
                // 原单据已生效时先按原方向回滚库存（原为入库退回则加回，原为出库退回则减回）
                if ((int)$exists['status'] === 1) {
                    $oldItems = $db->fetchAll("SELECT product_id, quantity FROM return_items WHERE order_id=:id", ['id' => $id]);
                    $oldDelta = (int)$exists['return_type'] === self::TYPE_OUT_RETURN ? -1.0 : 1.0;
                    foreach ($oldItems as $oi) {
                        $this->adjustInventory($db, (int)$exists['warehouse_id'], (int)$oi['product_id'], $oldDelta * (float)$oi['quantity']);
                    }
                }
                $db->delete('return_items', 'order_id = :id', ['id' => $id]);
                $db->update('return_orders', [
                    'return_type'  => $data['return_type'],
                    'warehouse_id' => $data['warehouse_id'],
                    'customer_id'  => $data['customer_id'],
                    'supplier_id'  => $data['supplier_id'],
                    'operator'     => $data['operator'],
                    'total_amount' => $data['total_amount'],
                    'return_date'  => $data['return_date'],
                    'status'       => $data['status'],
                    'remark'       => $data['remark'],
                ], 'id = :id', ['id' => $id]);

                foreach ($data['items'] as $row) {
                    $db->insert('return_items', [
                        'order_id'     => $id,
                        'product_id'   => $row['product_id'],
                        'quantity'     => $row['quantity'],
                        'unit_price'   => $row['unit_price'],
                        'amount'       => $row['amount'],
                        'remark'       => $row['remark'],
                    ]);
                    if ((int)$data['status'] === 1) {
                        $delta = $data['return_type'] === self::TYPE_OUT_RETURN ? (float)$row['quantity'] : -(float)$row['quantity'];
                        $this->adjustInventory($db, $data['warehouse_id'], $row['product_id'], $delta);
                    }
                }
            });
            $this->ok('编辑成功');
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 删除（AJAX） */
    public function destroy(): void
    {
        Csrf::verify();
        $id = (int)input('id', 0);
        $order = $this->db->fetch("SELECT id, status, return_type, warehouse_id, production_cancel_id FROM return_orders WHERE id=:id", ['id' => $id]);
        if (!$order) {
            $this->fail('记录不存在');
        }
        if ((int)$order['production_cancel_id'] > 0) {
            $this->fail('该单据由生产取消自动生成，请在生产管理中操作');
        }
        try {
            $this->db->transaction(function ($db) use ($id, $order) {
                // 已生效单删除需按原方向回滚库存（原为出库反回则减回，原为入库退回则加回）
                if ((int)$order['status'] === 1) {
                    $items = $db->fetchAll("SELECT product_id, quantity FROM return_items WHERE order_id=:id", ['id' => $id]);
                    $delta = (int)$order['return_type'] === self::TYPE_OUT_RETURN ? -1.0 : 1.0;
                    foreach ($items as $row) {
                        $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$row['product_id'], $delta * (float)$row['quantity']);
                    }
                }
                $db->delete('return_items', 'order_id = :id', ['id' => $id]);
                $db->delete('return_orders', 'id = :id', ['id' => $id]);
            });
            $this->ok('删除成功');
        } catch (\RuntimeException $e) {
            $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 待退回单「已完成」（AJAX）：状态 0→1，同一事务内按退回方向实际变动库存 */
    public function complete(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT id, status, return_type, warehouse_id FROM return_orders WHERE id=:id",
            ['id' => $id]
        );
        if (!$order) {
            $this->fail('记录不存在');
        }
        if ((int)$order['status'] !== 0) {
            $this->fail('该单据不是待办状态，无需完成');
        }
        $rows = $this->db->fetchAll("SELECT product_id, quantity FROM return_items WHERE order_id=:id", ['id' => $id]);
        if (!$rows) {
            $this->fail('退回单没有明细，无法完成');
        }
        $items = array_map(static fn ($r) => ['product_id' => (int)$r['product_id'], 'quantity' => (float)$r['quantity']], $rows);
        // 入库退回（退回供应商，出仓方向）完成前先校验库存
        if ((int)$order['return_type'] === self::TYPE_IN_RETURN) {
            $stockErr = $this->checkStock((int)$order['warehouse_id'], $items);
            if ($stockErr !== '') {
                $this->fail($stockErr);
            }
        }
        try {
            $this->db->transaction(function ($db) use ($id, $order, $rows) {
                foreach ($rows as $row) {
                    // 出库退回（客户退货）加库存；入库退回（退回供应商）扣库存
                    $delta = (int)$order['return_type'] === self::TYPE_OUT_RETURN ? (float)$row['quantity'] : -(float)$row['quantity'];
                    $this->adjustInventory($db, (int)$order['warehouse_id'], (int)$row['product_id'], $delta);
                }
                $db->update('return_orders', ['status' => 1], 'id = :id', ['id' => $id]);
            });
            $this->ok('已完成，库存已更新');
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
            $where .= ' AND (o.order_no LIKE :q1 OR w.name LIKE :q2 OR s.name LIKE :q3 OR c.name LIKE :q4)';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $search . '%';
        }
        if ($categoryId > 0) {
            $where .= ' AND EXISTS (SELECT 1 FROM return_items ri2 JOIN products p2 ON ri2.product_id = p2.id
                       WHERE ri2.order_id = o.id AND p2.category_id = :cid)';
            $params['cid'] = $categoryId;
        }
        if ($from !== '') {
            $where .= ' AND o.return_date >= :df';
            $params['df'] = $from;
        }
        if ($to !== '') {
            $where .= ' AND o.return_date <= :dt';
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
            "SELECT o.*, w.name AS warehouse_name, s.name AS supplier_name, c.name AS customer_name,
                    io.order_no AS inbound_no, oo.order_no AS outbound_no
             FROM return_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN suppliers s ON o.supplier_id = s.id
             LEFT JOIN customers c ON o.customer_id = c.id
             LEFT JOIN inbound_orders io ON o.return_type = 2 AND o.source_id = io.id
             LEFT JOIN outbound_orders oo ON o.return_type = 1 AND o.source_id = oo.id
             WHERE $where ORDER BY o.id DESC",
            $params
        );

        $itemsByOrder = $this->loadItems($orders);

        echo $this->render('return/print', [
            'title'         => '退回单',
            'orders'        => $orders,
            'itemsByOrder'  => $itemsByOrder,
            'siteName'      => $this->siteName(),
            'printer'       => Auth::instance()->name(), // 经办人显示当前打印操作人
        ]);
    }

    /** 打印单张退回单 */
    public function detail(): void
    {
        $id = (int)input('id', 0);
        $order = $this->db->fetch(
            "SELECT o.*, w.name AS warehouse_name, s.name AS supplier_name, c.name AS customer_name,
                    io.order_no AS inbound_no, oo.order_no AS outbound_no
             FROM return_orders o
             LEFT JOIN warehouses w ON o.warehouse_id = w.id
             LEFT JOIN suppliers s ON o.supplier_id = s.id
             LEFT JOIN customers c ON o.customer_id = c.id
             LEFT JOIN inbound_orders io ON o.return_type = 2 AND o.source_id = io.id
             LEFT JOIN outbound_orders oo ON o.return_type = 1 AND o.source_id = oo.id
             WHERE o.id=:id",
            ['id' => $id]
        );
        if (!$order) {
            echo '<div style="padding:30px;text-align:center;color:#999">退回单不存在</div>';
            return;
        }
        $items = $this->loadItems([$order])[$id] ?? [];
        echo $this->render('return/print', [
            'title'         => '退回单详情',
            'orders'        => [$order],
            'itemsByOrder'  => [$id => $items],
            'siteName'      => $this->siteName(),
            'printer'      => Auth::instance()->name(), // 经办人显示当前打印操作人
        ]);
    }

    // ---------- 内部 ----------

    /** 批量加载退回单明细（带商品信息） */
    private function loadItems(array $orders): array
    {
        $itemsByOrder = [];
        if (!$orders) {
            return $itemsByOrder;
        }
        $ids = array_column($orders, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT i.order_id, i.product_id, i.quantity, i.unit_price, i.amount, i.remark,
                       p.code AS product_code, p.name AS product_name, p.spec AS product_spec, p.unit AS product_unit
                FROM return_items i
                LEFT JOIN products p ON i.product_id = p.id
                WHERE i.order_id IN ($in)";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $itemsByOrder[$row['order_id']][] = $row;
        }
        return $itemsByOrder;
    }

    /** 同步库存：增量调整（delta 负值代表退回扣减） */
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
                throw new \RuntimeException('库存不足，无法退回');
            }
            $db->update('inventory', ['quantity' => $newQty], 'id = :id', ['id' => $exists['id']]);
        } elseif ($delta < 0) {
            throw new \RuntimeException('库存不足，无法退回');
        } elseif ($delta > 0) {
            $db->insert('inventory', [
                'warehouse_id' => $warehouseId,
                'product_id'   => $productId,
                'quantity'     => $delta,
            ]);
        }
    }

    /** 入库退回新增时的库存预检（按新明细聚合）；返回错误文案，空串=通过 */
    private function checkStock(int $warehouseId, array $items): string
    {
        $need = [];
        foreach ($items as $row) {
            $pid = (int)$row['product_id'];
            $need[$pid] = ($need[$pid] ?? 0) + (float)$row['quantity'];
        }
        foreach ($need as $pid => $qty) {
            $stock = (float)$this->db->fetchColumn(
                "SELECT quantity FROM inventory WHERE warehouse_id = :w AND product_id = :p",
                ['w' => $warehouseId, 'p' => $pid]
            );
            if ($stock < $qty) {
                $p = $this->db->fetch("SELECT name FROM products WHERE id = :id", ['id' => $pid]);
                return '库存不足：' . ($p['name'] ?? '商品' . $pid) . '（需要 ' . $qty . '，库存 ' . $stock . '）';
            }
        }
        return '';
    }

    /** 入库退回编辑时的库存预检：原单已生效则旧占用先加回，仅校验新增量 */
    private function checkStockForUpdate(int $warehouseId, array $newItems, int $returnOrderId, bool $oldActive): string
    {
        $oldMap = [];
        if ($oldActive) {
            $oldRows = $this->db->fetchAll(
                "SELECT product_id, SUM(quantity) AS qty FROM return_items WHERE order_id = :id GROUP BY product_id",
                ['id' => $returnOrderId]
            );
            foreach ($oldRows as $r) {
                $oldMap[(int)$r['product_id']] = (float)$r['qty'];
            }
        }
        $newMap = [];
        foreach ($newItems as $row) {
            $pid = (int)$row['product_id'];
            $newMap[$pid] = ($newMap[$pid] ?? 0) + (float)$row['quantity'];
        }
        $allPids = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
        foreach ($allPids as $pid) {
            $extra = ($newMap[$pid] ?? 0) - ($oldMap[$pid] ?? 0);
            if ($extra <= 0) {
                continue;
            }
            $stock = (float)$this->db->fetchColumn(
                "SELECT quantity FROM inventory WHERE warehouse_id = :w AND product_id = :p",
                ['w' => $warehouseId, 'p' => $pid]
            );
            if ($stock < $extra) {
                $p = $this->db->fetch("SELECT name FROM products WHERE id = :id", ['id' => $pid]);
                return '库存不足：' . ($p['name'] ?? '商品' . $pid) . '（需新增 ' . $extra . '，库存 ' . $stock . '）';
            }
        }
        return '';
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
                    'amount'     => $amount,
                    'remark'     => $remark,
                ];
            }
        }

        return [
            'return_type'  => (int)input('return_type', 1) === self::TYPE_IN_RETURN ? self::TYPE_IN_RETURN : self::TYPE_OUT_RETURN,
            'warehouse_id' => (int)input('warehouse_id', 0),
            'customer_id'  => (int)input('customer_id', 0),
            'supplier_id'  => (int)input('supplier_id', 0),
            // 经办人固定为当前登录操作人，不可由前端指定
            'operator'     => \Core\Auth::instance()->name(),
            'return_date'  => trim((string)input('return_date', date('Y-m-d'))),
            'status'       => (int)input('status', 0) === 1 ? 1 : 0,
            'remark'       => trim((string)input('remark', '')),
            'total_amount' => (float)input('total_amount', 0),
            'items'        => $cleanItems,
        ];
    }

    /** 校验主表 + 明细 */
    private function validateOrder(array $data): array
    {
        $errors = [];
        if ($data['warehouse_id'] <= 0) {
            $errors[] = '请选择退回仓库';
        }
        if ($data['return_type'] === self::TYPE_IN_RETURN) {
            if ($data['supplier_id'] <= 0) {
                $errors[] = '请选择供应商';
            }
        } else {
            if ($data['customer_id'] <= 0) {
                $errors[] = '请选择客户';
            }
        }
        if ($data['return_date'] === '') {
            $errors[] = '请选择退回日期';
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
