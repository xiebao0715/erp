<?php
/**
 * 产线管理
 * - 配置驱动通用 CRUD（与仓库管理一致）
 * - 产线含负责人，供生产管理下发生产任务时选择目标产线
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\BaseCrudController;

final class LineController extends BaseCrudController
{
    protected string $module  = 'line';
    protected string $title   = '产线管理';
    protected string $table   = 'production_lines';
    protected array $searchable = ['code', 'name', 'location', 'manager'];
    protected array $columns = [
        ['key' => 'code',       'label' => '产线编码'],
        ['key' => 'name',       'label' => '产线名称'],
        ['key' => 'location',   'label' => '所在车间'],
        ['key' => 'manager',    'label' => '负责人'],
        ['key' => 'phone',      'label' => '联系电话'],
        ['key' => 'status',     'label' => '状态', 'type' => 'status'],
        ['key' => 'created_at', 'label' => '创建时间', 'type' => 'datetime'],
    ];
    protected array $formFields = [
        ['name' => 'code',     'label' => '产线编码', 'type' => 'text',     'required' => true, 'unique' => true, 'placeholder' => '如 PL001'],
        ['name' => 'name',     'label' => '产线名称', 'type' => 'text',     'required' => true],
        ['name' => 'location', 'label' => '所在车间', 'type' => 'text'],
        ['name' => 'manager',  'label' => '负责人',   'type' => 'text'],
        ['name' => 'phone',    'label' => '联系电话', 'type' => 'text'],
        ['name' => 'remark',   'label' => '备注',     'type' => 'textarea'],
        ['name' => 'status',   'label' => '状态',     'type' => 'select', 'required' => true, 'options' => ['1' => '启用', '0' => '停用']],
    ];

    protected function canDelete(int $id): bool
    {
        // 已被生产任务引用的产线不可删除
        return $this->db->count('production_orders', 'production_line_id = :l', ['l' => $id]) === 0;
    }
}
