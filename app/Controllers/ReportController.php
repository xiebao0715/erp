<?php
/**
 * 报表统计
 * - index: 按日期范围汇总入库/出库/库存等
 * - print: 打印
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;

final class ReportController extends BaseController
{
    protected string $module = 'report';

    /** 报表首页 */
    public function index(): void
    {
        $today = date('Y-m-d');
        $firstDay = date('Y-m-01');
        $from = trim((string)input('from', $firstDay));
        $to = trim((string)input('to', $today));
        if ($from === '') { $from = $firstDay; }
        if ($to === '') { $to = $today; }
        $params = [':from' => $from, ':to' => $to . ' 23:59:59'];

        // 入库汇总（仅已入库）
        $inboundStats = $this->db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amount, COALESCE(SUM(quantity),0) AS qty
             FROM inbound_orders o LEFT JOIN inbound_items i ON i.order_id = o.id
             WHERE o.status=1 AND o.inbound_date BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );
        // 出库汇总
        $outboundStats = $this->db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amount, COALESCE(SUM(quantity),0) AS qty
             FROM outbound_orders o LEFT JOIN outbound_items i ON i.order_id = o.id
             WHERE o.status=1 AND o.outbound_date BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );
        // 库存现状
        $inventoryStats = $this->db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(quantity),0) AS qty
             FROM inventory"
        );
        // 库存预警数
        $lowCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM inventory i JOIN products p ON i.product_id=p.id WHERE i.quantity < p.min_stock"
        );

        // 入库明细
        $inboundItems = $this->db->fetchAll(
            "SELECT o.order_no, o.inbound_date, w.name AS warehouse_name, s.name AS supplier_name,
                    o.total_amount, o.operator
             FROM inbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id=w.id
             LEFT JOIN suppliers s ON o.supplier_id=s.id
             WHERE o.status=1 AND o.inbound_date BETWEEN :from AND :to
             ORDER BY o.inbound_date DESC",
            [':from' => $from, ':to' => $to]
        );
        $outboundItems = $this->db->fetchAll(
            "SELECT o.order_no, o.outbound_date, w.name AS warehouse_name, c.name AS customer_name,
                    o.total_amount, o.operator
             FROM outbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id=w.id
             LEFT JOIN customers c ON o.customer_id=c.id
             WHERE o.status=1 AND o.outbound_date BETWEEN :from AND :to
             ORDER BY o.outbound_date DESC",
            [':from' => $from, ':to' => $to]
        );

        $this->view('report/index', [
            'title'         => '报表统计',
            'from'          => $from,
            'to'            => $to,
            'inboundStats'  => $inboundStats,
            'outboundStats' => $outboundStats,
            'inventoryStats'=> $inventoryStats,
            'lowCount'      => $lowCount,
            'inboundItems'  => $inboundItems,
            'outboundItems' => $outboundItems,
            'canPrint'      => Auth::instance()->isAdmin() ? true : \Core\Permission::check($this->module, 'print'),
        ]);
    }

    /** 打印 */
    public function print(): void
    {
        $today = date('Y-m-d');
        $firstDay = date('Y-m-01');
        $from = trim((string)input('from', $firstDay));
        $to = trim((string)input('to', $today));
        if ($from === '') { $from = $firstDay; }
        if ($to === '') { $to = $today; }

        $inboundStats = $this->db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amount
             FROM inbound_orders WHERE status=1 AND inbound_date BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );
        $outboundStats = $this->db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amount
             FROM outbound_orders WHERE status=1 AND outbound_date BETWEEN :from AND :to",
            [':from' => $from, ':to' => $to]
        );
        $inventoryStats = $this->db->fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(quantity),0) AS qty
             FROM inventory"
        );
        $inboundItems = $this->db->fetchAll(
            "SELECT o.order_no, o.inbound_date, w.name AS warehouse_name, s.name AS supplier_name,
                    o.total_amount, o.operator
             FROM inbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id=w.id
             LEFT JOIN suppliers s ON o.supplier_id=s.id
             WHERE o.status=1 AND o.inbound_date BETWEEN :from AND :to
             ORDER BY o.inbound_date DESC",
            [':from' => $from, ':to' => $to]
        );
        $outboundItems = $this->db->fetchAll(
            "SELECT o.order_no, o.outbound_date, w.name AS warehouse_name, c.name AS customer_name,
                    o.total_amount, o.operator
             FROM outbound_orders o
             LEFT JOIN warehouses w ON o.warehouse_id=w.id
             LEFT JOIN customers c ON o.customer_id=c.id
             WHERE o.status=1 AND o.outbound_date BETWEEN :from AND :to
             ORDER BY o.outbound_date DESC",
            [':from' => $from, ':to' => $to]
        );

        echo $this->render('report/print', [
            'title'         => '报表统计',
            'from'          => $from,
            'to'            => $to,
            'inboundStats'  => $inboundStats,
            'outboundStats' => $outboundStats,
            'inventoryStats'=> $inventoryStats,
            'inboundItems'  => $inboundItems,
            'outboundItems' => $outboundItems,
            'siteName'      => $this->siteName(),
        ]);
    }

    private function siteName(): string
    {
        $s = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'site_name'");
        return $s['value'] ?? '工厂仓库 ERP 管理系统';
    }
}
