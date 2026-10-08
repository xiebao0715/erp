<?php
/**
 * 仪表盘：首页概览统计
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\BaseController;
use Core\Auth;
use Core\Permission;

final class DashboardController extends BaseController
{
    protected string $module = 'dashboard';

    public function index(): void
    {
        $auth = Auth::instance();
        $isAdmin = $auth->isAdmin();

        $stats = [];
        // 按权限展示统计
        if ($isAdmin || Permission::check('warehouse', 'view')) {
            $stats['warehouses'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM warehouses WHERE status=1");
        }
        if ($isAdmin || Permission::check('product', 'view')) {
            $stats['products'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM products WHERE status=1");
        }
        if ($isAdmin || Permission::check('supplier', 'view')) {
            $stats['suppliers'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM suppliers WHERE status=1");
        }
        if ($isAdmin || Permission::check('customer', 'view')) {
            $stats['customers'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM customers WHERE status=1");
        }
        if ($isAdmin || Permission::check('inbound', 'view')) {
            $stats['inbound_today'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM inbound_orders WHERE inbound_date=CURDATE() AND status=1");
            $stats['inbound_today_amount'] = (float)$this->db->fetchColumn("SELECT IFNULL(SUM(total_amount),0) FROM inbound_orders WHERE inbound_date=CURDATE() AND status=1");
            $stats['inbound_total'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM inbound_orders");
        }
        if ($isAdmin || Permission::check('outbound', 'view')) {
            $stats['outbound_today'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM outbound_orders WHERE outbound_date=CURDATE() AND status=1");
            $stats['outbound_today_amount'] = (float)$this->db->fetchColumn("SELECT IFNULL(SUM(total_amount),0) FROM outbound_orders WHERE outbound_date=CURDATE() AND status=1");
            $stats['outbound_total'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM outbound_orders");
        }
        if ($isAdmin || Permission::check('production', 'view')) {
            $stats['production_today'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM production_orders WHERE production_date=CURDATE()");
            $stats['production_today_qty'] = (float)$this->db->fetchColumn("SELECT IFNULL(SUM(quantity),0) FROM production_orders WHERE production_date=CURDATE() AND status<>3");
        }
        if ($isAdmin || Permission::check('return', 'view')) {
            $stats['return_today'] = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM return_orders WHERE return_date=CURDATE()");
            $stats['return_today_amount'] = (float)$this->db->fetchColumn("SELECT IFNULL(SUM(total_amount),0) FROM return_orders WHERE return_date=CURDATE()");
        }

        // 库存预警
        $lowStock = [];
        if ($isAdmin || Permission::check('inventory', 'view')) {
            $lowStock = $this->db->fetchAll(
                "SELECT p.code,p.name,p.spec,p.unit,p.min_stock,
                        COALESCE((SELECT SUM(quantity) FROM inventory i WHERE i.product_id=p.id),0) AS stock
                 FROM products p
                 WHERE p.status=1 AND p.min_stock>0
                 HAVING stock < p.min_stock
                 ORDER BY (p.min_stock - stock) DESC
                 LIMIT 10"
            );
        }

        // 最近入库/出库/退回
        $recentInbound = ($isAdmin || Permission::check('inbound', 'view'))
            ? $this->db->fetchAll("SELECT * FROM inbound_orders ORDER BY id DESC LIMIT 5") : [];
        $recentOutbound = ($isAdmin || Permission::check('outbound', 'view'))
            ? $this->db->fetchAll("SELECT * FROM outbound_orders ORDER BY id DESC LIMIT 5") : [];
        $recentReturn = ($isAdmin || Permission::check('return', 'view'))
            ? $this->db->fetchAll("SELECT * FROM return_orders ORDER BY id DESC LIMIT 5") : [];

        $this->view('dashboard/index', [
            'title'         => '仪表盘',
            'stats'         => $stats,
            'lowStock'      => $lowStock,
            'recentInbound' => $recentInbound,
            'recentOutbound'=> $recentOutbound,
            'recentReturn'  => $recentReturn,
            'isAdmin'       => $isAdmin,
        ]);
    }
}
