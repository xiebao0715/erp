-- =====================================================================
-- 工厂仓库 ERP 管理系统 - 数据库结构
-- 由 install.php 在部署引导时自动执行；也可手动导入。
-- 字符集：utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 管理员 / 成员账号
-- is_admin=1 为系统管理员（拥有全部权限，不可删除）
-- permissions 存 JSON：{"warehouse":["view","create",...], "product":[...]}
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(60) NOT NULL COMMENT '登录用户名',
  `password` VARCHAR(255) NOT NULL COMMENT '密码哈希(password_hash)',
  `name` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '姓名',
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否管理员',
  `permissions` TEXT NULL COMMENT '权限JSON',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '启用状态 1启用 0禁用',
  `last_login` DATETIME NULL COMMENT '最近登录时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统账号';

-- ---------------------------------------------------------------------
-- 权限分配日志（成员权限每次变更的前后内容、操作人与变更摘要）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `permission_logs`;
CREATE TABLE `permission_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限分配日志';

-- ---------------------------------------------------------------------
-- 仓库
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `warehouses`;
CREATE TABLE `warehouses` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(60) NOT NULL COMMENT '仓库编码',
  `name` VARCHAR(120) NOT NULL COMMENT '仓库名称',
  `location` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '仓库地址',
  `manager` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '负责人',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='仓库';

-- ---------------------------------------------------------------------
-- 商品分类
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `product_categories`;
CREATE TABLE `product_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(60) NOT NULL COMMENT '分类名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品分类';

-- ---------------------------------------------------------------------
-- 商品单位
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `product_units`;
CREATE TABLE `product_units` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(10) NOT NULL COMMENT '单位名称',
  `sort` INT NOT NULL DEFAULT 0 COMMENT '排序（小在前）',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品单位';

INSERT INTO `product_units` (`name`, `sort`) VALUES
('个', 0), ('件', 1), ('箱', 2), ('台', 3), ('套', 4), ('包', 5), ('米', 6), ('千克', 7);

-- ---------------------------------------------------------------------
-- 商品
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(60) NOT NULL COMMENT '商品编码',
  `name` VARCHAR(120) NOT NULL COMMENT '商品名称',
  `category` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '分类（旧字段，兼容保留）',
  `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分类ID（关联 product_categories）',
  `spec` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '规格型号',
  `unit` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '单位',
  `cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '成本价',
  `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '售价',
  `min_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '最低库存预警',
  `max_stock` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '最高库存',
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '默认仓库',
  `is_producible` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否可生产 1是 0否',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_name` (`name`),
  KEY `idx_category` (`category`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_producible` (`is_producible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品';

-- ---------------------------------------------------------------------
-- 供应商
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(60) NOT NULL COMMENT '供应商编码',
  `name` VARCHAR(120) NOT NULL COMMENT '供应商名称',
  `contact` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '地址',
  `email` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '邮箱',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='供应商';

-- ---------------------------------------------------------------------
-- 客户
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(60) NOT NULL COMMENT '客户编码',
  `name` VARCHAR(120) NOT NULL COMMENT '客户名称',
  `contact` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '联系人',
  `phone` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '联系电话',
  `address` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '地址',
  `email` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '邮箱',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户';

-- ---------------------------------------------------------------------
-- 入库单（主表）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `inbound_orders`;
CREATE TABLE `inbound_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL COMMENT '入库单号',
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '入库仓库',
  `supplier_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商',
  `production_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生产任务单ID（生产完工入库单）',
  `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '总金额',
  `inbound_date` DATE NOT NULL COMMENT '入库日期',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已入库 0待入库',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_production` (`production_id`),
  KEY `idx_date` (`inbound_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='入库单';

-- 入库明细
DROP TABLE IF EXISTS `inbound_items`;
CREATE TABLE `inbound_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL COMMENT '入库单ID',
  `product_id` INT UNSIGNED NOT NULL COMMENT '商品ID',
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '数量',
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='入库明细';

-- ---------------------------------------------------------------------
-- 出库单（主表）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `outbound_orders`;
CREATE TABLE `outbound_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL COMMENT '出库单号',
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '出库仓库',
  `customer_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户',
  `production_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生产任务单ID（生产领料出库单）',
  `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '总金额',
  `outbound_date` DATE NOT NULL COMMENT '出库日期',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已出库 0待出库',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_production` (`production_id`),
  KEY `idx_date` (`outbound_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='出库单';

-- 出库明细
DROP TABLE IF EXISTS `outbound_items`;
CREATE TABLE `outbound_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL COMMENT '出库单ID',
  `product_id` INT UNSIGNED NOT NULL COMMENT '商品ID',
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '数量',
  `unit_price` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '单价',
  `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '金额',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='出库明细';

-- ---------------------------------------------------------------------
-- 生产方案（BOM）：每生产 1 件成品消耗的材料及单位用量
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `production_boms`;
CREATE TABLE `production_boms` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL COMMENT '生产商品ID（成品）',
  `material_id` INT UNSIGNED NOT NULL COMMENT '消耗商品ID（材料）',
  `quantity` DECIMAL(12,4) NOT NULL DEFAULT 1.0000 COMMENT '单位用量（每生产1件成品的消耗量）',
  `unit` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '消耗单位（冗余材料单位）',
  `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '备注',
  `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '下发人',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_material` (`product_id`, `material_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_material` (`material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产方案（BOM 消耗明细）';

-- ---------------------------------------------------------------------
-- 生产任务单（完工后自动生成生产领料出库单 outbound_orders.production_id 关联）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `production_orders`;
CREATE TABLE `production_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL COMMENT '生产任务单号',
  `product_id` INT UNSIGNED NOT NULL COMMENT '生产商品ID（成品）',
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '生产数量',
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '领料仓库（消耗材料出库仓库）',
  `production_line_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '目标产线ID',
  `inbound_warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '成品入库仓库ID',
  `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '消耗材料成本合计',
  `production_date` DATE NOT NULL COMMENT '生产日期',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0待生产 2生产中(已领料出库，成品未入库) 1已完成（已生成领料出库单与完工入库单） 3已取消（数量全部取消后自动完结）',
  `outbound_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '自动生成的生产领料出库单ID',
  `inbound_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '自动生成的完工成品入库单ID',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_product` (`product_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_line` (`production_line_id`),
  KEY `idx_inbound_wh` (`inbound_warehouse_id`),
  KEY `idx_outbound` (`outbound_id`),
  KEY `idx_inbound` (`inbound_id`),
  KEY `idx_date` (`production_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产任务单';

-- 生产任务消耗材料快照（下单时按 BOM × 生产数量 计算）
DROP TABLE IF EXISTS `production_items`;
CREATE TABLE `production_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产任务消耗材料快照';

-- ---------------------------------------------------------------------
-- 生产取消单（生产中取消部分/全部数量，确认后按比例退回消耗材料）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `production_cancel_items`;
DROP TABLE IF EXISTS `production_cancels`;
CREATE TABLE `production_cancels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL COMMENT '取消单号',
  `production_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '生产任务单ID',
  `quantity` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT '取消生产数量',
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '材料退回仓库（任务领料仓库）',
  `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '退回消耗材料成本合计',
  `cancel_date` DATE NOT NULL COMMENT '取消日期',
  `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0待取消 1已取消（已退回消耗材料/扣减成品并扣减生产数量）',
  `material_returned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '确认取消时是否已把材料退回领料仓库',
  `product_deducted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '确认取消时是否已扣减成品库存',
  `task_status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '确认取消时任务状态：0待生产（不生成退回台账） 2生产中（退材料） 1已完成（退材料+扣成品）',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_production` (`production_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产取消单';

-- 生产取消退回材料明细（按取消数量占任务数量的比例计算）
CREATE TABLE `production_cancel_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='生产取消退回材料快照';

-- ---------------------------------------------------------------------
-- 产线（生产任务下发的目标产线，含负责人）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `production_lines`;
CREATE TABLE `production_lines` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='产线';

-- 退回单（区分类型：1出库反回=客户退货入仓加库存 / 2入库退回=退回供应商出仓扣库存；source_id 关联来源出库单/入库单）
DROP TABLE IF EXISTS `return_orders`;
CREATE TABLE `return_orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no` VARCHAR(40) NOT NULL COMMENT '退回单号',
  `return_type` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '退回类型 1出库反回(客户退货入仓) 2入库退回(退回供应商出仓)',
  `source_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源单据ID（入库单/出库单），0=历史独立退回单',
  `production_cancel_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '来源生产取消单ID（>0 表示由生产取消同步生成的台账单，禁止在退回管理删除）',
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '退回仓库',
  `customer_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '客户（出库反回）',
  `supplier_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '供应商（入库退回）',
  `operator` VARCHAR(60) NOT NULL DEFAULT '' COMMENT '经办人',
  `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '总金额',
  `return_date` DATE NOT NULL COMMENT '退回日期',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1已完成 0待办（入库退回=待退回/已退回；出库反回=待反回/已反回）',
  `remark` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '备注',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_no` (`order_no`),
  KEY `idx_source` (`source_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_date` (`return_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退回单';

-- 退回明细
DROP TABLE IF EXISTS `return_items`;
CREATE TABLE `return_items` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='退回明细';

-- ---------------------------------------------------------------------
-- 库存（按仓库 + 商品维度，由入/出库应用层维护）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `warehouse_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '仓库ID',
  `product_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品ID',
  `quantity` DECIMAL(14,2) NOT NULL DEFAULT 0.00 COMMENT '当前库存',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wh_prod` (`warehouse_id`, `product_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='库存';

-- ---------------------------------------------------------------------
-- 系统设置（键值对）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key` VARCHAR(60) NOT NULL COMMENT '键',
  `value` TEXT NULL COMMENT '值',
  `label` VARCHAR(120) NOT NULL DEFAULT '' COMMENT '说明',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置';

-- 默认设置
INSERT INTO `settings` (`key`, `value`, `label`) VALUES
('site_name',     '工厂仓库 ERP 管理系统', '站点名称'),
('company_name',  '示例工厂',              '公司名称'),
('company_phone', '',                       '公司电话'),
('company_addr',  '',                       '公司地址'),
('low_stock_threshold', '0',               '库存预警阈值(0=按商品最低库存)'),
('currency',      '¥',                      '货币符号'),
('print_title',   '工厂仓库 ERP 管理系统',  '打印抬头'),
('login_bg',      '',                       '登录背景图片'),
('login_bg_enabled', '0',                   '登录自定义背景开关');

-- ---------------------------------------------------------------------
-- 操作日志（审计）
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `operation_logs`;
CREATE TABLE `operation_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `username` VARCHAR(60) NOT NULL DEFAULT '',
  `module` VARCHAR(60) NOT NULL DEFAULT '',
  `action` VARCHAR(60) NOT NULL DEFAULT '',
  `detail` TEXT NULL,
  `ip` VARCHAR(60) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_module` (`module`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='操作日志';

SET FOREIGN_KEY_CHECKS = 1;
