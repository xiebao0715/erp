<?php
/**
 * 客户管理
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\BaseCrudController;

final class CustomerController extends BaseCrudController
{
    protected string $module   = 'customer';
    protected string $title    = '客户管理';
    protected string $table    = 'customers';
    protected array $searchable = ['code', 'name', 'contact', 'phone'];
    protected array $columns = [
        ['key' => 'code',       'label' => '客户编码'],
        ['key' => 'name',       'label' => '客户名称'],
        ['key' => 'contact',    'label' => '联系人'],
        ['key' => 'phone',      'label' => '联系电话'],
        ['key' => 'address',    'label' => '地址'],
        ['key' => 'email',      'label' => '邮箱'],
        ['key' => 'status',     'label' => '状态', 'type' => 'status'],
        ['key' => 'created_at', 'label' => '创建时间', 'type' => 'datetime'],
    ];
    protected array $formFields = [
        ['name' => 'code',    'label' => '客户编码', 'type' => 'text', 'required' => true, 'unique' => true, 'placeholder' => '如 C001'],
        ['name' => 'name',    'label' => '客户名称', 'type' => 'text', 'required' => true],
        ['name' => 'contact', 'label' => '联系人',   'type' => 'text'],
        ['name' => 'phone',   'label' => '联系电话', 'type' => 'text'],
        ['name' => 'address', 'label' => '地址',     'type' => 'text'],
        ['name' => 'email',   'label' => '邮箱',     'type' => 'text'],
        ['name' => 'remark',  'label' => '备注',     'type' => 'textarea'],
        ['name' => 'status',  'label' => '状态',     'type' => 'select', 'options' => ['1' => '启用', '0' => '停用']],
    ];

    protected function canDelete(int $id): bool
    {
        return $this->db->count('outbound_orders', 'customer_id = :c', ['c' => $id]) === 0;
    }
}
