<?php
/**
 * 供应商管理
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\BaseCrudController;

final class SupplierController extends BaseCrudController
{
    protected string $module   = 'supplier';
    protected string $title    = '供应商管理';
    protected string $table    = 'suppliers';
    protected array $searchable = ['code', 'name', 'contact', 'phone'];
    protected array $columns = [
        ['key' => 'code',       'label' => '供应商编码'],
        ['key' => 'name',       'label' => '供应商名称'],
        ['key' => 'contact',    'label' => '联系人'],
        ['key' => 'phone',      'label' => '联系电话'],
        ['key' => 'address',    'label' => '地址'],
        ['key' => 'email',      'label' => '邮箱'],
        ['key' => 'status',     'label' => '状态', 'type' => 'status'],
        ['key' => 'created_at', 'label' => '创建时间', 'type' => 'datetime'],
    ];
    protected array $formFields = [
        ['name' => 'code',    'label' => '供应商编码', 'type' => 'text', 'required' => true, 'unique' => true, 'placeholder' => '如 S001'],
        ['name' => 'name',    'label' => '供应商名称', 'type' => 'text', 'required' => true],
        ['name' => 'contact', 'label' => '联系人',     'type' => 'text'],
        ['name' => 'phone',   'label' => '联系电话',   'type' => 'text'],
        ['name' => 'address', 'label' => '地址',       'type' => 'text'],
        ['name' => 'email',   'label' => '邮箱',       'type' => 'text'],
        ['name' => 'remark',  'label' => '备注',       'type' => 'textarea'],
        ['name' => 'status',  'label' => '状态',       'type' => 'select', 'options' => ['1' => '启用', '0' => '停用']],
    ];

    protected function canDelete(int $id): bool
    {
        return $this->db->count('inbound_orders', 'supplier_id = :s', ['s' => $id]) === 0;
    }
}
