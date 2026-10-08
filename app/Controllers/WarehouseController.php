<?php
/**
 * 仓库管理
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\BaseCrudController;

final class WarehouseController extends BaseCrudController
{
    protected string $module  = 'warehouse';
    protected string $title   = '仓库管理';
    protected string $table   = 'warehouses';
    protected array $searchable = ['code', 'name', 'location', 'manager'];
    protected array $columns = [
        ['key' => 'code',      'label' => '仓库编码'],
        ['key' => 'name',      'label' => '仓库名称'],
        ['key' => 'location',  'label' => '仓库地址'],
        ['key' => 'manager',   'label' => '负责人'],
        ['key' => 'phone',     'label' => '联系电话'],
        ['key' => 'status',    'label' => '状态', 'type' => 'status'],
        ['key' => 'created_at','label' => '创建时间', 'type' => 'datetime'],
    ];
    protected array $formFields = [
        ['name' => 'code',     'label' => '仓库编码', 'type' => 'text',     'required' => true, 'unique' => true, 'placeholder' => '如 WH001'],
        ['name' => 'name',     'label' => '仓库名称', 'type' => 'text',     'required' => true],
        ['name' => 'location', 'label' => '仓库地址', 'type' => 'text'],
        ['name' => 'manager',  'label' => '负责人',   'type' => 'text'],
        ['name' => 'phone',    'label' => '联系电话', 'type' => 'text'],
        ['name' => 'remark',   'label' => '备注',     'type' => 'textarea'],
        ['name' => 'status',   'label' => '状态',     'type' => 'select', 'required' => true, 'options' => ['1' => '启用', '0' => '停用']],
    ];

    protected function canDelete(int $id): bool
    {
        // 有商品的仓库不可删除
        $cnt = $this->db->count('products', 'warehouse_id = :w', ['w' => $id]);
        return $cnt === 0;
    }
}
