<?php
/**
 * 数据库结构升级器（幂等）
 * 已安装的系统在访问时自动检测结构版本，缺表缺列自动补齐，旧数据自动迁移。
 * 通过 settings 表的 schema_version 键记录当前版本。
 */
declare(strict_types=1);

namespace Core;

final class Upgrade
{
    /** 当前代码要求的数据库结构版本 */
    private const VERSION = 21;

    /** 由 index.php 在加载配置后调用；任何异常都静默跳过，不阻塞系统启动 */
    public static function run(array $config): void
    {
        try {
            $db = Database::instance($config['db'] ?? []);
            self::migrate($db);
        } catch (\Throwable) {
            // 数据库暂不可用或结构异常时静默跳过，避免影响页面渲染
        }
    }

    private static function migrate(Database $db): void
    {
        $row = null;
        try {
            $row = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'schema_version'");
        } catch (\Throwable) {
            return; // settings 表不存在 → 尚未安装
        }
        $version = $row ? (int)$row['value'] : 0;
        if ($version >= self::VERSION) {
            return;
        }

        // ---- v2：商品分类 + 订单类型/状态重构 ----

        // 1. 商品分类表
        $db->query(
            "CREATE TABLE IF NOT EXISTS `product_categories` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(60) NOT NULL COMMENT '分类名称',
                `sort` INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类'"
        );

        // 2. products.category_id 列
        if (!self::hasColumn($db, 'products', 'category_id')) {
            $db->query("ALTER TABLE `products` ADD COLUMN `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID' AFTER `category`");
            $db->query("ALTER TABLE `products` ADD KEY `idx_category_id` (`category_id`)");
        }

        // 3. 迁移旧分类文本 → 分类表并回填 category_id
        $oldCats = $db->fetchAll(
            "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category <> ''"
        );
        foreach ($oldCats as $c) {
            $name = trim((string)$c['category']);
            if ($name === '') {
                continue;
            }
            $exists = $db->fetch("SELECT id FROM product_categories WHERE name = :n", ['n' => $name]);
            $cid = $exists ? (int)$exists['id'] : (int)$db->insert('product_categories', ['name' => $name, 'sort' => 0]);
            $db->query(
                "UPDATE products SET category_id = :cid WHERE category = :name AND category_id = 0",
                ['cid' => $cid, 'name' => $name]
            );
        }

        // 4. orders.order_type 列（v12 起订单模块移除，新库无 orders 表，跳过）
        if (self::hasTable($db, 'orders')) {
            if (!self::hasColumn($db, 'orders', 'order_type')) {
                $db->query("ALTER TABLE `orders` ADD COLUMN `order_type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '订单类型 1出库 2入库' AFTER `order_no`");
                $db->query("ALTER TABLE `orders` ADD KEY `idx_type` (`order_type`)");
            }

            // 5. 旧订单状态迁移（0待处理→10未出货 1已确认→11生产中 2已完成→12已出货 3已取消→14退回），旧单默认出库类
            $db->query("UPDATE orders SET order_type = 1 WHERE order_type IS NULL OR order_type = 0");
            $db->query("UPDATE orders SET status = 10 WHERE status = 0");
            $db->query("UPDATE orders SET status = 11 WHERE status = 1");
            $db->query("UPDATE orders SET status = 12 WHERE status = 2");
            $db->query("UPDATE orders SET status = 14 WHERE status = 3");
        }

        // ---- v3：商品单位表 ----
        $db->query(
            "CREATE TABLE IF NOT EXISTS `product_units` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(10) NOT NULL COMMENT '单位名称',
                `sort` INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品单位'"
        );
        // 空表时初始化常用单位
        $unitCount = (int)$db->fetchColumn("SELECT COUNT(*) FROM product_units");
        if ($unitCount === 0) {
            foreach (['个', '件', '箱', '台', '套', '包', '米', '千克'] as $i => $u) {
                $db->insert('product_units', ['name' => $u, 'sort' => $i]);
            }
        }

        // ---- v4：订单联动出入库 ----
        // orders 增加供应商/仓库；出入库单增加来源订单（v12 起订单模块移除，新库不再补这些结构）
        if (self::hasTable($db, 'orders')) {
            if (!self::hasColumn($db, 'orders', 'supplier_id')) {
                $db->query("ALTER TABLE `orders` ADD COLUMN `supplier_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商（入库订单）' AFTER `customer_id`");
            }
            if (!self::hasColumn($db, 'orders', 'warehouse_id')) {
                $db->query("ALTER TABLE `orders` ADD COLUMN `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '出入库仓库' AFTER `supplier_id`");
                $db->query("ALTER TABLE `orders` ADD KEY `idx_owh` (`warehouse_id`)");
            }
            if (!self::hasColumn($db, 'inbound_orders', 'source_order_id')) {
                $db->query("ALTER TABLE `inbound_orders` ADD COLUMN `source_order_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源订单ID' AFTER `remark`");
                $db->query("ALTER TABLE `inbound_orders` ADD KEY `idx_source` (`source_order_id`)");
            }
            if (!self::hasColumn($db, 'outbound_orders', 'source_order_id')) {
                $db->query("ALTER TABLE `outbound_orders` ADD COLUMN `source_order_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源订单ID' AFTER `remark`");
                $db->query("ALTER TABLE `outbound_orders` ADD KEY `idx_source` (`source_order_id`)");
            }
        }

        // ---- v5：订单分批处理（部分出库 / 部分入库） ----
        // 订单明细记录累计已处理数量，由「处理」操作按数量分批生成出入库单
        if (self::hasTable($db, 'order_items') && !self::hasColumn($db, 'order_items', 'processed_qty')) {
            $db->query("ALTER TABLE `order_items` ADD COLUMN `processed_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '已处理数量（分批出入库累计）' AFTER `quantity`");
        }

        // ---- v6：订单退回 ----
        // 订单明细记录累计已退回数量；「退回」按数量执行，累计不能超过订单数量
        if (self::hasTable($db, 'order_items') && !self::hasColumn($db, 'order_items', 'returned_qty')) {
            $db->query("ALTER TABLE `order_items` ADD COLUMN `returned_qty` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '已退回数量（累计）' AFTER `processed_qty`");
        }

        // ---- v7：退回管理（独立退回单，区分出库退回/入库退回两种类型） ----
        $db->query(
            "CREATE TABLE IF NOT EXISTS `return_orders` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` VARCHAR(40) NOT NULL COMMENT '退回单号',
                `return_type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '退回类型 1出库退回(客户退货入仓) 2入库退回(退回供应商出仓)',
                `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '退回仓库',
                `customer_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户（出库退回）',
                `supplier_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商（入库退回）',
                `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
                `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '总金额',
                `return_date` DATE NOT NULL COMMENT '退回日期',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已退回 0草稿',
                `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_warehouse` (`warehouse_id`),
                KEY `idx_customer` (`customer_id`),
                KEY `idx_supplier` (`supplier_id`),
                KEY `idx_date` (`return_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退回单'"
        );
        $db->query(
            "CREATE TABLE IF NOT EXISTS `return_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL COMMENT '退回单ID',
                `product_id` INT UNSIGNED NOT NULL COMMENT '商品ID',
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '数量',
                `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
                `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_product` (`product_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退回明细'"
        );

        // ---- v8：退回单区分类型（出库退回=客户退货入仓加库存 / 入库退回=退回供应商出仓扣库存） ----
        // 对已按 v7 建过退回表的环境补列
        if (!self::hasColumn($db, 'return_orders', 'return_type')) {
            $db->query("ALTER TABLE `return_orders` ADD COLUMN `return_type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '退回类型 1出库退回(客户退货入仓) 2入库退回(退回供应商出仓)' AFTER `order_no`");
        }
        if (!self::hasColumn($db, 'return_orders', 'customer_id')) {
            $db->query("ALTER TABLE `return_orders` ADD COLUMN `customer_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户（出库退回）' AFTER `warehouse_id`");
            $db->query("ALTER TABLE `return_orders` ADD KEY `idx_customer` (`customer_id`)");
        }

        // ---- v9：退回明细行级往来单位（每行选择目标客户/供应商，退回数量≤其订单总量） ----
        // v12 起订单模块移除，新库不再补该列（既有库由 v12 迁移删除）
        if (self::hasTable($db, 'orders') && !self::hasColumn($db, 'return_items', 'partner_id')) {
            $db->query("ALTER TABLE `return_items` ADD COLUMN `partner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '往来单位ID（客户或供应商，按主单退回类型）' AFTER `product_id`");
            $db->query("ALTER TABLE `return_items` ADD KEY `idx_partner` (`partner_id`)");
        }

        // ---- v10：退回明细改为按订单退回（选订单 → 按订单明细退回，联动订单 returned_qty 与状态） ----
        if (self::hasTable($db, 'orders') && !self::hasColumn($db, 'return_items', 'src_order_id')) {
            $db->query("ALTER TABLE `return_items` ADD COLUMN `src_order_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源订单ID（orders.id）' AFTER `product_id`");
            $db->query("ALTER TABLE `return_items` ADD KEY `idx_src_order` (`src_order_id`)");
        }
        if (self::hasTable($db, 'orders') && !self::hasColumn($db, 'return_items', 'src_item_id')) {
            $db->query("ALTER TABLE `return_items` ADD COLUMN `src_item_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源订单明细ID（order_items.id）' AFTER `src_order_id`");
            $db->query("ALTER TABLE `return_items` ADD KEY `idx_src_item` (`src_item_id`)");
        }

        // ---- v11：登录页设置（公司名称同步登录页、自定义登录背景） ----
        $defaultSettings = [
            'company_name'     => ['示例工厂', '公司名称'],
            'login_bg'         => ['',       '登录背景图片'],
            'login_bg_enabled' => ['0',      '登录自定义背景开关'],
        ];
        foreach ($defaultSettings as $key => [$value, $label]) {
            $exists = $db->fetch("SELECT id FROM settings WHERE `key` = :k", ['k' => $key]);
            if (!$exists) {
                $db->insert('settings', ['key' => $key, 'value' => $value, 'label' => $label]);
            }
        }

        // ---- v12：移除订单管理模块 ----
        // 0) 历史退回单往来单位回填到主单（旧数据的客户/供应商记录在明细行，删列前先迁移）
        if (self::hasTable($db, 'orders') && self::hasColumn($db, 'return_items', 'src_order_id')) {
            $db->query(
                "UPDATE return_orders o
                 JOIN (SELECT order_id, MAX(src_order_id) AS sid FROM return_items WHERE src_order_id > 0 GROUP BY order_id) x ON x.order_id = o.id
                 JOIN orders oo ON oo.id = x.sid
                 SET o.customer_id = oo.customer_id
                 WHERE o.return_type = 1 AND o.customer_id = 0 AND oo.customer_id > 0"
            );
            $db->query(
                "UPDATE return_orders o
                 JOIN (SELECT order_id, MAX(src_order_id) AS sid FROM return_items WHERE src_order_id > 0 GROUP BY order_id) x ON x.order_id = o.id
                 JOIN orders oo ON oo.id = x.sid
                 SET o.supplier_id = oo.supplier_id
                 WHERE o.return_type = 2 AND o.supplier_id = 0 AND oo.supplier_id > 0"
            );
        }
        if (self::hasColumn($db, 'return_items', 'partner_id')) {
            $db->query(
                "UPDATE return_orders o
                 JOIN (SELECT order_id, MAX(partner_id) AS pid FROM return_items WHERE partner_id > 0 GROUP BY order_id) x ON x.order_id = o.id
                 SET o.customer_id = x.pid
                 WHERE o.return_type = 1 AND o.customer_id = 0"
            );
            $db->query(
                "UPDATE return_orders o
                 JOIN (SELECT order_id, MAX(partner_id) AS pid FROM return_items WHERE partner_id > 0 GROUP BY order_id) x ON x.order_id = o.id
                 SET o.supplier_id = x.pid
                 WHERE o.return_type = 2 AND o.supplier_id = 0"
            );
        }
        // 1) 入/出库单不再关联来源订单；退回单改为独立退货单（往来单位取主单 customer_id/supplier_id）
        self::dropColumn($db, 'inbound_orders', 'source_order_id', 'idx_source');
        self::dropColumn($db, 'outbound_orders', 'source_order_id', 'idx_source');
        self::dropColumn($db, 'return_items', 'src_order_id', 'idx_src_order');
        self::dropColumn($db, 'return_items', 'src_item_id', 'idx_src_item');
        self::dropColumn($db, 'return_items', 'partner_id', 'idx_partner');
        // 2) 删除订单主表/明细表及其全部数据
        $db->query("DROP TABLE IF EXISTS `order_items`");
        $db->query("DROP TABLE IF EXISTS `orders`");
        // 3) 清理成员权限 JSON 中残留的 order 模块键
        foreach ($db->fetchAll("SELECT id, permissions FROM users WHERE permissions IS NOT NULL AND permissions <> ''") as $u) {
            $perms = json_decode((string)$u['permissions'], true);
            if (is_array($perms) && array_key_exists('order', $perms)) {
                unset($perms['order']);
                $db->update('users', ['permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => $u['id']]);
            }
        }

        // ---- v13：生产管理（商品可生产标识 + 生产方案 BOM + 生产任务单，任务完工自动生成生产领料出库单） ----
        // 1) 商品「是否可生产」标识
        if (!self::hasColumn($db, 'products', 'is_producible')) {
            $db->query("ALTER TABLE `products` ADD COLUMN `is_producible` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否可生产 1是 0否' AFTER `warehouse_id`");
            $db->query("ALTER TABLE `products` ADD KEY `idx_producible` (`is_producible`)");
        }
        // 2) 生产方案（BOM）：每个可生产商品对应若干行消耗材料，quantity = 每生产 1 件成品的消耗量
        $db->query(
            "CREATE TABLE IF NOT EXISTS `production_boms` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id` INT UNSIGNED NOT NULL COMMENT '生产商品ID（成品）',
                `material_id` INT UNSIGNED NOT NULL COMMENT '消耗商品ID（材料）',
                `quantity` DECIMAL(12,4) NOT NULL DEFAULT 1.0000 COMMENT '单位用量（每生产1件成品的消耗量）',
                `unit` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '消耗单位（冗余材料单位）',
                `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_product_material` (`product_id`, `material_id`),
                KEY `idx_product` (`product_id`),
                KEY `idx_material` (`material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产方案（BOM 消耗明细）'"
        );
        // 3) 生产任务单
        $db->query(
            "CREATE TABLE IF NOT EXISTS `production_orders` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` VARCHAR(40) NOT NULL COMMENT '生产任务单号',
                `product_id` INT UNSIGNED NOT NULL COMMENT '生产商品ID（成品）',
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '生产数量',
                `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '领料仓库（消耗材料出库仓库）',
                `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
                `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '消耗材料成本合计',
                `production_date` DATE NOT NULL COMMENT '生产日期',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已生产(已生成领料出库单) 0草稿',
                `outbound_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '自动生成的生产领料出库单ID',
                `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_product` (`product_id`),
                KEY `idx_warehouse` (`warehouse_id`),
                KEY `idx_outbound` (`outbound_id`),
                KEY `idx_date` (`production_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产任务单'"
        );
        // 4) 生产任务消耗材料快照（下单时按 BOM × 生产数量 计算）
        $db->query(
            "CREATE TABLE IF NOT EXISTS `production_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL COMMENT '生产任务单ID',
                `material_id` INT UNSIGNED NOT NULL COMMENT '消耗商品ID',
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '消耗数量（单位用量×生产数量）',
                `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价（取材料成本价）',
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
                `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_material` (`material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产任务消耗材料快照'"
        );
        // 5) 出库单关联生产任务（生产领料单由生产模块维护，出库模块不可直接编辑/删除）
        if (!self::hasColumn($db, 'outbound_orders', 'production_id')) {
            $db->query("ALTER TABLE `outbound_orders` ADD COLUMN `production_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生产任务单ID（生产领料出库单）' AFTER `customer_id`");
            $db->query("ALTER TABLE `outbound_orders` ADD KEY `idx_production` (`production_id`)");
        }

        // ---- v14：产线管理（生产任务下发到目标产线） ----
        $db->query(
            "CREATE TABLE IF NOT EXISTS `production_lines` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(60) NOT NULL COMMENT '产线编码',
                `name` VARCHAR(120) NOT NULL COMMENT '产线名称',
                `location` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '所在车间/位置',
                `manager` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '负责人',
                `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
                `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产线'"
        );
        if (!self::hasColumn($db, 'production_orders', 'production_line_id')) {
            $db->query("ALTER TABLE `production_orders` ADD COLUMN `production_line_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '目标产线ID' AFTER `warehouse_id`");
            $db->query("ALTER TABLE `production_orders` ADD KEY `idx_line` (`production_line_id`)");
        }

        // ---- v15：单据待办状态 + 生产完工成品自动入库 ----
        // 入库单增加生产任务关联（生产完工自动生成的成品入库单）
        if (!self::hasColumn($db, 'inbound_orders', 'production_id')) {
            $db->query("ALTER TABLE `inbound_orders` ADD COLUMN `production_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生产任务单ID（生产完工入库单）' AFTER `supplier_id`");
            $db->query("ALTER TABLE `inbound_orders` ADD KEY `idx_production` (`production_id`)");
        }
        // 生产任务：成品入库仓库 + 完工后关联的成品入库单；状态 0待生产 2生产中 1已完成
        if (!self::hasColumn($db, 'production_orders', 'inbound_warehouse_id')) {
            $db->query("ALTER TABLE `production_orders` ADD COLUMN `inbound_warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '成品入库仓库ID' AFTER `production_line_id`");
            $db->query("ALTER TABLE `production_orders` ADD KEY `idx_inbound_wh` (`inbound_warehouse_id`)");
        }
        if (!self::hasColumn($db, 'production_orders', 'inbound_id')) {
            $db->query("ALTER TABLE `production_orders` ADD COLUMN `inbound_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '完工成品入库单ID' AFTER `outbound_id`");
            $db->query("ALTER TABLE `production_orders` ADD KEY `idx_inbound` (`inbound_id`)");
        }
        // 统一刷新状态列语义：单据待办流程（0待办 1已完成）；生产任务默认待生产
        $db->query("ALTER TABLE `inbound_orders` MODIFY COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已入库 0待入库'");
        $db->query("ALTER TABLE `outbound_orders` MODIFY COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已出库 0待出库'");
        $db->query("ALTER TABLE `return_orders` MODIFY COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已退回 0待退回'");
        $db->query("ALTER TABLE `production_orders` MODIFY COLUMN `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0待生产 2生产中(已领料出库，成品未入库) 1已完成（已生成领料出库单与完工入库单）'");

        // ---- v16：入库退回 / 出库反回由来源单发起 + 生产取消（按比例退回消耗材料） ----
        // 退回单增加来源单据（入库单/出库单），不再支持手工新增独立退回单
        if (!self::hasColumn($db, 'return_orders', 'source_id')) {
            $db->query("ALTER TABLE `return_orders` ADD COLUMN `source_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源单据ID（入库单/出库单），0=历史独立退回单' AFTER `return_type`");
            $db->query("ALTER TABLE `return_orders` ADD KEY `idx_source` (`source_id`)");
        }
        $db->query("ALTER TABLE `return_orders` MODIFY COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已完成 0待办（入库退回=待退回/已退回；出库反回=待反回/已反回）'");
        // 生产取消单主表 + 明细
        $db->query(
            "CREATE TABLE IF NOT EXISTS `production_cancels` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` VARCHAR(40) NOT NULL COMMENT '取消单号',
                `production_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生产任务单ID',
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '取消生产数量',
                `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '材料退回仓库（任务领料仓库）',
                `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
                `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '退回消耗材料成本合计',
                `cancel_date` DATE NOT NULL COMMENT '取消日期',
                `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0待取消 1已取消（已退回消耗材料）',
                `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_production` (`production_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产取消单'"
        );
        $db->query(
            "CREATE TABLE IF NOT EXISTS `production_cancel_items` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cancel_id` INT UNSIGNED NOT NULL COMMENT '生产取消单ID',
                `material_id` INT UNSIGNED NOT NULL COMMENT '消耗商品ID',
                `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '按取消比例退回的材料数量',
                `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价（材料成本价）',
                `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
                `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
                PRIMARY KEY (`id`),
                KEY `idx_cancel` (`cancel_id`),
                KEY `idx_material` (`material_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产取消退回材料快照'"
        );

        // ---- v17：取消生产重构（数量同步扣减、全取消自动完结、回滚依据列）----
        if (self::hasTable($db, 'production_cancels')) {
            if (!self::hasColumn($db, 'production_cancels', 'material_returned')) {
                $db->query("ALTER TABLE `production_cancels` ADD COLUMN `material_returned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '确认取消时是否已把材料退回领料仓库' AFTER `status`");
            }
            if (!self::hasColumn($db, 'production_cancels', 'product_deducted')) {
                $db->query("ALTER TABLE `production_cancels` ADD COLUMN `product_deducted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '确认取消时是否已扣减成品库存' AFTER `material_returned`");
            }
        }
        if ($version < 17 && self::hasTable($db, 'production_cancels')) {
            // 存量已确认取消单：旧逻辑确认时已把材料退回领料仓库
            $db->query("UPDATE `production_cancels` SET `material_returned` = 1 WHERE `status` = 1");
            // 存量任务材料快照按已确认取消明细回减（对齐新模型：快照=剩余消耗量）
            $db->query(
                "UPDATE `production_items` pi
                 JOIN (
                     SELECT pc.production_id, pci.material_id, SUM(pci.quantity) AS q
                     FROM `production_cancel_items` pci
                     JOIN `production_cancels` pc ON pc.id = pci.cancel_id
                     WHERE pc.status = 1
                     GROUP BY pc.production_id, pci.material_id
                 ) x ON x.production_id = pi.order_id AND x.material_id = pi.material_id
                 SET pi.quantity = GREATEST(0, ROUND(pi.quantity - x.q, 2))"
            );
            // 存量任务生产数量同步扣减已确认取消量
            $db->query(
                "UPDATE `production_orders` po
                 SET po.quantity = GREATEST(0, ROUND(po.quantity - (
                     SELECT COALESCE(SUM(pc.quantity),0) FROM `production_cancels` pc
                     WHERE pc.production_id = po.id AND pc.status = 1
                 ), 2))
                 WHERE EXISTS (SELECT 1 FROM `production_cancels` pc2 WHERE pc2.production_id = po.id AND pc2.status = 1)"
            );
            // 数量已全部取消的任务自动标记为「已取消」
            $db->query(
                "UPDATE `production_orders` po SET po.status = 3
                 WHERE po.quantity <= 0 AND po.status <> 3
                   AND EXISTS (SELECT 1 FROM `production_cancels` pc WHERE pc.production_id = po.id AND pc.status = 1)"
            );
        }

        // ---- v18：退回单可关联生产取消单（生产取消退回/扣减同步到退回管理台账）----
        if (!self::hasColumn($db, 'return_orders', 'production_cancel_id')) {
            $db->query("ALTER TABLE `return_orders` ADD COLUMN `production_cancel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源生产取消单ID（>0 表示由生产取消同步生成的台账单，禁止在退回管理删除）' AFTER `source_id`");
            $db->query("ALTER TABLE `return_orders` ADD KEY `idx_pc` (`production_cancel_id`)");
        }

        // ---- v19：取消单记录确认取消时的任务状态（退回管理台账按阶段显示 生产中退回/完成后退回）----
        if (!self::hasColumn($db, 'production_cancels', 'task_status')) {
            $db->query("ALTER TABLE `production_cancels` ADD COLUMN `task_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '确认取消时任务状态：0待生产 2生产中 1已完成' AFTER `product_deducted`");
        }
        if ($version < 19 && self::hasTable($db, 'production_cancels') && self::hasColumn($db, 'return_orders', 'production_cancel_id')) {
            // 存量数据修复：按已生成的退回台账类型反推取消时任务状态
            $db->query(
                "UPDATE `production_cancels` pc SET pc.task_status = 1
                 WHERE pc.status = 1 AND pc.product_deducted = 1"
            );
            $db->query(
                "UPDATE `production_cancels` pc SET pc.task_status = 2
                 WHERE pc.status = 1 AND pc.product_deducted = 0 AND pc.material_returned = 1"
            );
        }

        // ---- v20：权限分配日志表（记录成员权限每次变更的前后内容与操作人）----
        if (!self::hasTable($db, 'permission_logs')) {
            $db->query(
                "CREATE TABLE `permission_logs` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `user_id` INT UNSIGNED NOT NULL COMMENT '被调整成员ID',
                  `user_name` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '被调整成员姓名快照',
                  `operator_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
                  `operator_name` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '操作人姓名',
                  `changes` TEXT NULL COMMENT '变更摘要（按模块列出新增/移除的操作）',
                  `old_perms` TEXT NULL COMMENT '变更前权限JSON',
                  `new_perms` TEXT NULL COMMENT '变更后权限JSON',
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限分配日志'"
            );
        }

        // ---- v21：生产方案记录下发人（保存方案时写入当前操作人）----
        if (!self::hasColumn($db, 'production_boms', 'operator')) {
            $db->query("ALTER TABLE `production_boms` ADD COLUMN `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '下发人' AFTER `remark`");
        }

        // 记录版本
        $ver = $db->fetch("SELECT id FROM settings WHERE `key` = 'schema_version'");
        if ($ver) {
            $db->update('settings', ['value' => (string)self::VERSION], "`key` = 'schema_version'", []);
        } else {
            $db->insert('settings', ['key' => 'schema_version', 'value' => (string)self::VERSION, 'label' => '数据库结构版本']);
        }
    }

    private static function hasTable(Database $db, string $table): bool
    {
        $r = $db->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t",
            ['t' => $table]
        );
        return (int)($r['c'] ?? 0) > 0;
    }

    /** 删除列（连同其单列索引）；表或列不存在时静默跳过，保证幂等 */
    private static function dropColumn(Database $db, string $table, string $column, ?string $index = null): void
    {
        if (!self::hasTable($db, $table) || !self::hasColumn($db, $table, $column)) {
            return;
        }
        if ($index !== null) {
            try {
                $db->query("ALTER TABLE `$table` DROP INDEX `$index`");
            } catch (\Throwable) {
                // 索引不存在则忽略
            }
        }
        try {
            $db->query("ALTER TABLE `$table` DROP COLUMN `$column`");
        } catch (\Throwable) {
            // 列已被删除则忽略
        }
    }

    private static function hasColumn(Database $db, string $table, string $column): bool
    {
        $r = $db->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c",
            ['t' => $table, 'c' => $column]
        );
        return (int)($r['c'] ?? 0) > 0;
    }
}
