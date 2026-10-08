<?php
/**
 * 库存管理
 * - index: 库存查询（关联 warehouse + product），按仓库/商品搜索 + 分页
 * - warning: 库存预警（库存低于商品 min_stock 或全局阈值）
 * - print / printWarning: 打印
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;

final class InventoryController extends BaseController
{
    protected string $module = 'inventory';

    /** 库存查询 */
    public function index(): void
    {
        $search = trim((string)input('q', ''));
        $warehouseId = (int)input('warehouse_id', 0);
        $productId = (int)input('product_id', 0);
        $categoryId = (int)input('category_id', 0);

        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (p.code LIKE :q1 OR p.name LIKE :q2 OR p.spec LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }
        if ($warehouseId > 0) {
            // 新商品（无任何库存行）在按仓库筛选时仍然显示，库存计 0
            $where .= ' AND (i.id IS NULL OR i.warehouse_id = :wid)';
            $params['wid'] = $warehouseId;
        }
        if ($productId > 0) {
            $where .= ' AND p.id = :pid';
            $params['pid'] = $productId;
        }
        if ($categoryId > 0) {
            $where .= ' AND p.category_id = :cid';
            $params['cid'] = $categoryId;
        }
        // 已有库存行全部显示；无库存行的商品仅启用状态显示为 0 库存虚拟行（仓库显示「未入库」）
        $where .= ' AND (i.id IS NOT NULL OR p.status = 1)';

        $countSql = "SELECT COUNT(*) FROM products p
                     LEFT JOIN inventory i ON i.product_id = p.id
                     LEFT JOIN warehouses w ON i.warehouse_id = w.id
                     WHERE $where";
        $listSql  = "SELECT i.id, i.warehouse_id, i.product_id, COALESCE(i.quantity, 0) AS quantity, i.updated_at,
                            p.code AS product_code, p.name AS product_name, p.spec AS product_spec,
                            p.unit AS product_unit, p.min_stock, p.max_stock, p.category_id,
                            pc.name AS category_name,
                            w.name AS warehouse_name, w.code AS warehouse_code
                     FROM products p
                     LEFT JOIN inventory i ON i.product_id = p.id
                     LEFT JOIN product_categories pc ON p.category_id = pc.id
                     LEFT JOIN warehouses w ON i.warehouse_id = w.id
                     WHERE $where
                     ORDER BY i.warehouse_id ASC, p.code ASC";
        $page = $this->listData($countSql, $listSql, $params, 15);
        $page['search'] = $search;

        $this->view('inventory/index', [
            'title'      => '库存管理',
            'page'       => $page,
            'warehouses' => $this->db->fetchAll("SELECT id, name FROM warehouses WHERE status=1 ORDER BY name"),
            'categories' => $this->db->fetchAll("SELECT id, name FROM product_categories ORDER BY sort ASC, id ASC"),
            'filterWarehouseId' => $warehouseId,
            'filterCategoryId'  => $categoryId,
            'canDelete'  => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'delete'),
            'tab'        => 'list',
            'canPrint'   => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'print'),
        ]);
    }

    /** 库存预警 */
    public function warning(): void
    {
        // 阈值：0 表示按商品 min_stock；否则用全局阈值
        $thresholdRow = $this->db->fetch("SELECT value FROM settings WHERE `key`='low_stock_threshold'");
        $globalThreshold = (float)($thresholdRow['value'] ?? 0);

        $search = trim((string)input('q', ''));
        $warehouseId = (int)input('warehouse_id', 0);
        $categoryId = (int)input('category_id', 0);

        $where = '1=1';
        $params = [];
        // 预警条件：库存低于商品 min_stock 或全局阈值；无库存行的新商品按 0 库存参与判断
        if ($globalThreshold > 0) {
            $where .= ' AND COALESCE(i.quantity, 0) < :thr';
            $params['thr'] = $globalThreshold;
        } else {
            $where .= ' AND COALESCE(i.quantity, 0) < p.min_stock';
        }
        if ($search !== '') {
            $where .= ' AND (p.code LIKE :q1 OR p.name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $search . '%';
        }
        if ($warehouseId > 0) {
            // 新商品（无任何库存行）在按仓库筛选时仍然参与预警，库存计 0
            $where .= ' AND (i.id IS NULL OR i.warehouse_id = :wid)';
            $params['wid'] = $warehouseId;
        }
        if ($categoryId > 0) {
            $where .= ' AND p.category_id = :cid';
            $params['cid'] = $categoryId;
        }
        // 无库存行的商品仅启用状态参与预警
        $where .= ' AND (i.id IS NOT NULL OR p.status = 1)';

        $countSql = "SELECT COUNT(*) FROM products p
                     LEFT JOIN inventory i ON i.product_id = p.id
                     LEFT JOIN warehouses w ON i.warehouse_id = w.id
                     WHERE $where";
        $listSql  = "SELECT i.id, i.warehouse_id, i.product_id, COALESCE(i.quantity, 0) AS quantity, i.updated_at,
                            p.code AS product_code, p.name AS product_name, p.spec AS product_spec,
                            p.unit AS product_unit, p.min_stock, p.max_stock, p.category_id,
                            pc.name AS category_name,
                            w.name AS warehouse_name, w.code AS warehouse_code
                     FROM products p
                     LEFT JOIN inventory i ON i.product_id = p.id
                     LEFT JOIN product_categories pc ON p.category_id = pc.id
                     LEFT JOIN warehouses w ON i.warehouse_id = w.id
                     WHERE $where
                     ORDER BY COALESCE(i.quantity, 0) ASC";
        $page = $this->listData($countSql, $listSql, $params, 15);
        $page['search'] = $search;

        $this->view('inventory/index', [
            'title'              => '库存预警',
            'page'               => $page,
            'warehouses'         => $this->db->fetchAll("SELECT id, name FROM warehouses WHERE status=1 ORDER BY name"),
            'categories'         => $this->db->fetchAll("SELECT id, name FROM product_categories ORDER BY sort ASC, id ASC"),
            'filterWarehouseId' => $warehouseId,
            'filterCategoryId'  => $categoryId,
            'canDelete'          => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'delete'),
            'globalThreshold'   => $globalThreshold,
            'tab'               => 'warning',
            'canPrint'          => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'print'),
        ]);
    }

    /** 详情数据（AJAX） */
    public function detail(): void
    {
        $id = (int)input('id', 0);
        $row = $this->db->fetch(
            "SELECT i.*, p.code AS product_code, p.name AS product_name, p.spec AS product_spec,
                    p.unit AS product_unit, p.min_stock, p.max_stock, pc.name AS category_name,
                    w.name AS warehouse_name, w.code AS warehouse_code
             FROM inventory i
             LEFT JOIN products p ON i.product_id = p.id
             LEFT JOIN product_categories pc ON p.category_id = pc.id
             LEFT JOIN warehouses w ON i.warehouse_id = w.id
             WHERE i.id = :id",
            ['id' => $id]
        );
        if (!$row) {
            $this->fail('记录不存在');
        }
        $this->json($row);
    }

    /** 查询商品当前库存（出库表单用）：warehouse_id=0 时为全部仓库合计 */
    public function stock(): void
    {
        $pid = (int)input('product_id', 0);
        $wid = (int)input('warehouse_id', 0);
        if ($pid <= 0) {
            $this->json(['quantity' => 0]);
            return;
        }
        if ($wid > 0) {
            $q = (float)$this->db->fetchColumn(
                "SELECT COALESCE(quantity, 0) FROM inventory WHERE product_id = :pid AND warehouse_id = :wid",
                ['pid' => $pid, 'wid' => $wid]
            );
        } else {
            $q = (float)$this->db->fetchColumn(
                "SELECT COALESCE(SUM(quantity), 0) FROM inventory WHERE product_id = :pid",
                ['pid' => $pid]
            );
        }
        $this->json(['quantity' => $q]);
    }

    /** 打印库存列表（支持分类筛选与 ids 指定记录） */
    public function print(): void
    {
        $search = trim((string)input('q', ''));
        $warehouseId = (int)input('warehouse_id', 0);
        $categoryId = (int)input('category_id', 0);

        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (p.code LIKE :q1 OR p.name LIKE :q2 OR p.spec LIKE :q3)';
            $params['q1'] = $params['q2'] = $params['q3'] = '%' . $search . '%';
        }
        if ($warehouseId > 0) {
            $where .= ' AND (i.id IS NULL OR i.warehouse_id = :wid)';
            $params['wid'] = $warehouseId;
        }
        if ($categoryId > 0) {
            $where .= ' AND p.category_id = :cid';
            $params['cid'] = $categoryId;
        }
        $idsRaw = trim((string)input('ids', ''));
        if ($idsRaw !== '') {
            $idList = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn ($v) => $v > 0));
            if ($idList) {
                $where .= ' AND i.id IN (' . implode(',', $idList) . ')';
            }
        }
        $where .= ' AND (i.id IS NOT NULL OR p.status = 1)';

        $items = $this->db->fetchAll(
            "SELECT i.id, i.warehouse_id, i.product_id, COALESCE(i.quantity, 0) AS quantity, i.updated_at,
                    p.code AS product_code, p.name AS product_name, p.spec AS product_spec,
                    p.unit AS product_unit, p.min_stock, p.max_stock, pc.name AS category_name,
                    w.name AS warehouse_name, w.code AS warehouse_code
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.id
             LEFT JOIN product_categories pc ON p.category_id = pc.id
             LEFT JOIN warehouses w ON i.warehouse_id = w.id
             WHERE $where
             ORDER BY i.warehouse_id ASC, p.code ASC",
            $params
        );
        echo $this->render('inventory/print', [
            'title'    => '库存清单',
            'items'    => $items,
            'siteName' => $this->siteName(),
        ]);
    }

    /** 打印库存预警（支持分类筛选与 ids 指定记录） */
    public function printWarning(): void
    {
        $thresholdRow = $this->db->fetch("SELECT value FROM settings WHERE `key`='low_stock_threshold'");
        $globalThreshold = (float)($thresholdRow['value'] ?? 0);

        $search = trim((string)input('q', ''));
        $warehouseId = (int)input('warehouse_id', 0);
        $categoryId = (int)input('category_id', 0);

        $where = '1=1';
        $params = [];
        if ($globalThreshold > 0) {
            $where .= ' AND COALESCE(i.quantity, 0) < :thr';
            $params['thr'] = $globalThreshold;
        } else {
            $where .= ' AND COALESCE(i.quantity, 0) < p.min_stock';
        }
        if ($search !== '') {
            $where .= ' AND (p.code LIKE :q1 OR p.name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $search . '%';
        }
        if ($warehouseId > 0) {
            $where .= ' AND (i.id IS NULL OR i.warehouse_id = :wid)';
            $params['wid'] = $warehouseId;
        }
        if ($categoryId > 0) {
            $where .= ' AND p.category_id = :cid';
            $params['cid'] = $categoryId;
        }
        $idsRaw = trim((string)input('ids', ''));
        if ($idsRaw !== '') {
            $idList = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn ($v) => $v > 0));
            if ($idList) {
                $where .= ' AND i.id IN (' . implode(',', $idList) . ')';
            }
        }
        $where .= ' AND (i.id IS NOT NULL OR p.status = 1)';

        $items = $this->db->fetchAll(
            "SELECT i.id, i.warehouse_id, i.product_id, COALESCE(i.quantity, 0) AS quantity, i.updated_at,
                    p.code AS product_code, p.name AS product_name, p.spec AS product_spec,
                    p.unit AS product_unit, p.min_stock, p.max_stock, pc.name AS category_name,
                    w.name AS warehouse_name, w.code AS warehouse_code
             FROM products p
             LEFT JOIN inventory i ON i.product_id = p.id
             LEFT JOIN product_categories pc ON p.category_id = pc.id
             LEFT JOIN warehouses w ON i.warehouse_id = w.id
             WHERE $where
             ORDER BY COALESCE(i.quantity, 0) ASC",
            $params
        );
        echo $this->render('inventory/print', [
            'title'    => '库存预警清单',
            'items'    => $items,
            'siteName' => $this->siteName(),
            'isWarning' => true,
        ]);
    }

    /** 删除库存记录（AJAX） */
    public function destroy(): void
    {
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id FROM inventory WHERE id = :id", ['id' => $id]);
        if (!$row) $this->fail('记录不存在');
        try {
            $this->db->delete('inventory', 'id = :id', ['id' => $id]);
            $this->ok('删除成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    private function siteName(): string
    {
        $s = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'site_name'");
        return $s['value'] ?? '工厂仓库 ERP 管理系统';
    }
}
