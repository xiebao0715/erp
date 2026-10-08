<?php
/**
 * 权限校验
 * - is_admin=1 拥有全部权限（不可删除）
 * - 普通成员按 users.permissions (JSON) 控制模块级 + 操作级权限
 * - user 模块（成员管理 / 权限设置）仅管理员可访问
 */
declare(strict_types=1);

namespace Core;

final class Permission
{
    /** 所有模块：key => 中文名 */
    public const MODULES = [
        'dashboard'  => '仪表盘',
        'warehouse'  => '仓库管理',
        'product'    => '商品管理',
        'basic'      => '基础设置',
        'inbound'    => '入库管理',
        'outbound'   => '出库管理',
        'production' => '生产管理',
        'line'       => '产线管理',
        'return'     => '退回管理',
        'inventory'  => '库存管理',
        'supplier'   => '供应商管理',
        'customer'   => '客户管理',
        'report'     => '报表统计',
        'settings'   => '系统设置',
    ];

    /** 所有操作：key => 中文名 */
    public const ACTIONS = [
        'view'    => '查看',
        'create'  => '新增',
        'edit'    => '编辑',
        'delete'  => '删除',
        'print'   => '打印',
        'operate' => '完成操作',
        'return'  => '退回/返回',
        'reverse' => '反回',
        'start'   => '开始生产',
        'cancel'  => '取消登记',
    ];

    /** 各模块实际支持的操作（权限设置弹窗仅显示模块支持的动作） */
    public const MODULE_ACTIONS = [
        'dashboard' => ['view'],
        'warehouse' => ['view', 'create', 'edit', 'delete', 'print'],
        'product'   => ['view', 'create', 'edit', 'delete', 'print'],
        'basic'     => ['view', 'create', 'edit', 'delete'],
        // 入库：operate=待入库单点「已完成」入库；return=已入库单发起「退回」
        'inbound'   => ['view', 'create', 'edit', 'operate', 'return', 'delete', 'print'],
        // 出库：operate=待出库单点「已完成」出库；reverse=已出库单发起「反回」
        'outbound'  => ['view', 'create', 'edit', 'operate', 'reverse', 'delete', 'print'],
        // 生产：start=开始生产/完成生产；cancel=登记取消（待取消）；return=确认取消（执行材料返回/成品退回）
        'production'=> ['view', 'create', 'edit', 'start', 'cancel', 'return', 'delete', 'print'],
        'line'      => ['view', 'create', 'edit', 'delete', 'print'],
        'return'    => ['view', 'edit', 'delete', 'print'], // 退回单由入库/出库/生产取消发起，不支持手工新增；编辑=待办单点「已完成」
        'inventory' => ['view', 'print', 'delete'],
        'supplier'  => ['view', 'create', 'edit', 'delete', 'print'],
        'customer'  => ['view', 'create', 'edit', 'delete', 'print'],
        'report'    => ['view', 'print'],
        'settings'  => ['view', 'edit'],
    ];

    private static ?array $cache = null;

    /** 获取当前用户某模块的允许操作列表 */
    public static function actions(string $module): array
    {
        $auth = Auth::instance();
        if (!$auth->check()) {
            return [];
        }
        if ($auth->isAdmin()) {
            return array_keys(self::ACTIONS);
        }
        $perms = self::load();
        return $perms[$module] ?? [];
    }

    /** 校验某模块某操作是否允许 */
    public static function check(string $module, string $action = 'view'): bool
    {
        $auth = Auth::instance();
        if (!$auth->check()) {
            return false;
        }
        if ($auth->isAdmin()) {
            return true;
        }
        // 成员管理 / 权限设置 仅管理员
        if ($module === 'user') {
            return false;
        }
        $perms = self::load();
        $allowed = $perms[$module] ?? [];
        return in_array($action, $allowed, true);
    }

    /** 解析当前用户权限 JSON */
    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $auth = Auth::instance();
        $user = $auth->user();
        $raw = $user['permissions'] ?? null;
        if (!$raw) {
            return self::$cache = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return self::$cache = [];
        }
        return self::$cache = $decoded;
    }

    /** 将前端提交的权限数组规整为标准结构（仅保留各模块支持的动作） */
    public static function normalize(array $input): array
    {
        $result = [];
        foreach (self::MODULES as $key => $label) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $validActions = self::MODULE_ACTIONS[$key] ?? array_keys(self::ACTIONS);
                $valid = array_intersect($validActions, $input[$key]);
                if ($valid) {
                    $result[$key] = array_values($valid);
                }
            }
        }
        return $result;
    }
}
