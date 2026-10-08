<?php
/**
 * 商品管理
 * - 商品 CRUD + 按分类筛选
 * - 分类/单位维护在「基础设置」(BasicController)
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\BaseCrudController;

final class ProductController extends BaseCrudController
{
    protected string $module    = 'product';
    protected string $title     = '商品管理';
    protected string $table     = 'products';
    protected string $idColumn  = 'p.id';
    protected string $selectFrom = 'products p
                                    LEFT JOIN warehouses w ON p.warehouse_id = w.id
                                    LEFT JOIN product_categories pc ON p.category_id = pc.id';
    protected string $selectCols = 'p.*, w.name AS warehouse_name, pc.name AS category_name';
    protected array $searchable = ['p.code', 'p.name', 'pc.name', 'p.spec'];
    protected array $columns = [
        ['key' => 'code',           'label' => '商品编码'],
        ['key' => 'name',           'label' => '商品名称'],
        ['key' => 'category_name',  'label' => '分类'],
        ['key' => 'spec',           'label' => '规格型号'],
        ['key' => 'unit',           'label' => '单位'],
        ['key' => 'cost',           'label' => '成本价',  'type' => 'amount'],
        ['key' => 'price',          'label' => '售价',    'type' => 'amount'],
        ['key' => 'warehouse_name', 'label' => '默认仓库'],
        ['key' => 'min_stock',      'label' => '最低库存', 'type' => 'number'],
        ['key' => 'is_producible',  'label' => '可生产',  'type' => 'yesno'],
        ['key' => 'status',         'label' => '状态',    'type' => 'status'],
    ];
    protected array $formFields = [
        ['name' => 'code',         'label' => '商品编码',  'type' => 'text',  'required' => true, 'unique' => true, 'placeholder' => '如 P001'],
        ['name' => 'name',         'label' => '商品名称',  'type' => 'text',  'required' => true],
        ['name' => 'category_id',  'label' => '分类',      'type' => 'select'],
        ['name' => 'spec',         'label' => '规格型号',  'type' => 'text'],
        ['name' => 'unit',         'label' => '单位',      'type' => 'select'],
        ['name' => 'cost',         'label' => '成本价',    'type' => 'number'],
        ['name' => 'price',        'label' => '售价',      'type' => 'number'],
        ['name' => 'min_stock',    'label' => '最低库存',  'type' => 'number', 'hint' => '低于此值触发预警'],
        ['name' => 'max_stock',    'label' => '最高库存',  'type' => 'number'],
        ['name' => 'warehouse_id', 'label' => '默认仓库',  'type' => 'select'],
        ['name' => 'is_producible', 'label' => '是否可生产', 'type' => 'select', 'options' => ['0' => '否', '1' => '是'], 'hint' => '标记为“是”后可在生产管理中选择该商品下达生产任务'],
        ['name' => 'remark',       'label' => '备注',      'type' => 'textarea'],
        ['name' => 'status',       'label' => '状态',      'type' => 'select', 'options' => ['1' => '启用', '0' => '停用']],
    ];

    /** 按分类 / 可生产标识筛选列表 */
    protected function listWhere(): string
    {
        $where = '1=1';
        $cid = (int)input('category_id', 0);
        if ($cid > 0) {
            $where .= ' AND p.category_id = ' . $cid;
        }
        $producible = trim((string)input('producible', ''));
        if ($producible === '1' || $producible === '0') {
            $where .= ' AND p.is_producible = ' . $producible;
        }
        return $where === '1=1' ? '' : $where;
    }

    /** 向视图追加分类数据（筛选下拉 + 分类管理弹窗） */
    protected function viewData(array $data): array
    {
        $data['categories'] = $this->categories();
        $data['filterCategoryId'] = (int)input('category_id', 0);
        $data['filterProducible'] = trim((string)input('producible', ''));
        return $data;
    }

    protected function prepareFields(array $fields): array
    {
        $warehouses = $this->db->fetchAll("SELECT id, name FROM warehouses WHERE status=1 ORDER BY name");
        $wOpts = [];
        foreach ($warehouses as $w) $wOpts[$w['id']] = $w['name'];

        $cOpts = ['0' => '未分类'];
        foreach ($this->categories() as $c) $cOpts[$c['id']] = $c['name'];

        $uOpts = [];
        foreach ($this->db->fetchAll("SELECT name FROM product_units ORDER BY sort ASC, id ASC") as $u) {
            $uOpts[$u['name']] = $u['name'];
        }

        foreach ($fields as &$f) {
            if ($f['name'] === 'warehouse_id') $f['options'] = $wOpts;
            if ($f['name'] === 'category_id') $f['options'] = $cOpts;
            if ($f['name'] === 'unit') $f['options'] = $uOpts;
        }
        return $fields;
    }

    // ---------- 内部 ----------

    /** 被生产方案引用（作为成品或消耗材料）的商品不允许删除，避免方案断链 */
    protected function canDelete(int $id): bool
    {
        return $this->db->count('production_boms', 'product_id = :p1 OR material_id = :p2', ['p1' => $id, 'p2' => $id]) === 0;
    }

    private function categories(): array
    {
        return $this->db->fetchAll("SELECT * FROM product_categories ORDER BY sort ASC, id ASC");
    }
}
