<?php
/**
 * 通用 CRUD 控制器：通过配置驱动，复用同一套增删改查逻辑与视图。
 * 简单模块（仓库/商品/供应商/客户）继承本类，仅声明配置即可。
 */
declare(strict_types=1);

namespace Core;

abstract class BaseCrudController extends BaseController
{
    /** 页面标题 */
    protected string $title = '';
    /** 数据表名 */
    protected string $table = '';
    /** 表格列：[['key'=>'name','label'=>'名称','type'=>'text'], ...] */
    protected array $columns = [];
    /** 表单字段：[['name'=>'name','label'=>'名称','type'=>'text','required'=>true,'options'=>['k'=>'v']], ...] */
    protected array $formFields = [];
    /** 搜索字段名 */
    protected array $searchable = [];
    /** 默认排序 SQL 片段 */
    protected string $orderBy = 'id DESC';
    /** 每页条数 */
    protected int $perPage = 15;
    /** 列表查询附加 JOIN/列 */
    protected string $selectFrom = ''; // 覆盖 FROM 子句（含关联查询）
    /** 自定义 SELECT 列（关联查询时使用，如 'p.*, w.name AS warehouse_name'） */
    protected string $selectCols = '*';
    /** 主表 id 列（含 JOIN 时需限定，如 'p.id'），批量打印 ids 用 */
    protected string $idColumn = 'id';

    /** 列表页 */
    public function index(): void
    {
        $search = trim((string)input('q', ''));
        $where = '1=1';
        $params = [];
        [$searchWhere, $searchParams] = $this->buildSearch($search);
        if ($searchWhere !== '') {
            $where .= ' AND ' . $searchWhere;
            $params = array_merge($params, $searchParams);
        }

        $extraWhere = $this->listWhere();
        if ($extraWhere !== '') {
            $where .= ' AND ' . $extraWhere;
        }

        $from = $this->selectFrom !== '' ? $this->selectFrom : $this->table;

        $countSql = "SELECT COUNT(*) FROM $from WHERE $where";
        $listSql  = "SELECT {$this->selectCols} FROM $from WHERE $where ORDER BY " . $this->orderBy;

        $page = $this->listData($countSql, $listSql, $params, $this->perPage);
        $page['search'] = $search;

        $this->view('crud/index', $this->viewData([
            'title'      => $this->title,
            'module'     => $this->module,
            'columns'    => $this->columns,
            'formFields' => $this->prepareFields($this->formFields),
            'page'       => $page,
            'canCreate'  => Auth::instance()->isAdmin() ? true : Permission::check($this->module, 'create'),
            'canEdit'    => Auth::instance()->isAdmin() ? true : Permission::check($this->module, 'edit'),
            'canDelete'  => Auth::instance()->isAdmin() ? true : Permission::check($this->module, 'delete'),
            'canPrint'   => Auth::instance()->isAdmin() ? true : Permission::check($this->module, 'print'),
        ]));
    }

    /** 子类可覆盖以追加列表过滤 */
    protected function listWhere(): string
    {
        return '';
    }

    /** 子类可覆盖以向视图追加数据（如分类下拉选项） */
    protected function viewData(array $data): array
    {
        return $data;
    }

    /** 子类可覆盖以注入动态下拉选项（如关联仓库） */
    protected function prepareFields(array $fields): array
    {
        return $fields;
    }

    /** 构建搜索条件。列名含 . 或 ( 视为已限定，原样使用；否则反引号包裹 */
    private function buildSearch(string $search): array
    {
        if ($search === '' || !$this->searchable) {
            return ['', []];
        }
        $ors = [];
        $params = [];
        $i = 0;
        foreach ($this->searchable as $col) {
            $key = 'q' . $i++;
            if (str_contains($col, '.') || str_contains($col, '(')) {
                $ors[] = "$col LIKE :$key";
            } else {
                $ors[] = "`$col` LIKE :$key";
            }
            $params[$key] = '%' . $search . '%';
        }
        return ['(' . implode(' OR ', $ors) . ')', $params];
    }

    /** 新增（AJAX） */
    public function store(): void
    {
        $data = $this->collectInput();
        $errors = $this->validate($data, false);
        if ($errors) {
            $this->fail(implode('；', $errors));
        }
        try {
            $this->beforeStore($data);
            $id = $this->db->insert($this->table, $data);
            $this->afterStore($id, $data);
            $this->ok('添加成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 编辑表单数据（AJAX 返回记录） */
    public function edit(): void
    {
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT * FROM {$this->table} WHERE id = :id", ['id' => $id]);
        if (!$row) {
            $this->fail('记录不存在');
        }
        $this->json($row);
    }

    /** 详情数据（AJAX 返回记录，关联展示列一并提供） */
    public function detail(): void
    {
        $id = (int)input('id', 0);
        $from = $this->selectFrom !== '' ? $this->selectFrom : $this->table;
        $row = $this->db->fetch(
            "SELECT {$this->selectCols} FROM $from WHERE {$this->idColumn} = :id",
            ['id' => $id]
        );
        if (!$row) {
            $this->fail('记录不存在');
        }
        $this->json($row);
    }

    /** 更新（AJAX） */
    public function update(): void
    {
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id FROM {$this->table} WHERE id = :id", ['id' => $id]);
        if (!$row) {
            $this->fail('记录不存在');
        }
        $data = $this->collectInput();
        $errors = $this->validate($data, true, $id);
        if ($errors) {
            $this->fail(implode('；', $errors));
        }
        try {
            $this->beforeUpdate($data, $id);
            $this->db->update($this->table, $data, 'id = :id', ['id' => $id]);
            $this->afterUpdate($id, $data);
            $this->ok('编辑成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 删除（AJAX） */
    public function destroy(): void
    {
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id FROM {$this->table} WHERE id = :id", ['id' => $id]);
        if (!$row) {
            $this->fail('记录不存在');
        }
        if (!$this->canDelete($id)) {
            $this->fail('该记录不可删除');
        }
        try {
            $this->beforeDelete($id);
            $this->db->delete($this->table, 'id = :id', ['id' => $id]);
            $this->afterDelete($id);
            $this->ok('删除成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 打印页面（支持 ids=1,2,3 指定记录） */
    public function print(): void
    {
        $search = trim((string)input('q', ''));
        $where = '1=1';
        $params = [];
        [$searchWhere, $searchParams] = $this->buildSearch($search);
        if ($searchWhere !== '') {
            $where .= ' AND ' . $searchWhere;
            $params = array_merge($params, $searchParams);
        }
        $extraWhere = $this->listWhere();
        if ($extraWhere !== '') {
            $where .= ' AND ' . $extraWhere;
        }
        // 指定记录批量打印
        $idsRaw = trim((string)input('ids', ''));
        if ($idsRaw !== '') {
            $idList = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), fn ($v) => $v > 0));
            if ($idList) {
                $where .= " AND {$this->idColumn} IN (" . implode(',', $idList) . ')';
            }
        }
        $from = $this->selectFrom !== '' ? $this->selectFrom : $this->table;
        $items = $this->db->fetchAll("SELECT {$this->selectCols} FROM $from WHERE $where ORDER BY " . $this->orderBy, $params);

        $data = [
            'title'    => $this->title,
            'columns'  => $this->columns,
            'items'    => $items,
            'siteName' => $this->siteName(),
        ];
        echo $this->render('crud/print', $data);
    }

    // ---- 钩子 ----
    protected function beforeStore(array &$data): void {}
    protected function afterStore(int $id, array $data): void {}
    protected function beforeUpdate(array &$data, int $id): void {}
    protected function afterUpdate(int $id, array $data): void {}
    protected function beforeDelete(int $id): void {}
    protected function afterDelete(int $id): void {}
    protected function canDelete(int $id): bool { return true; }

    // ---- 内部辅助 ----
    protected function collectInput(): array
    {
        $data = [];
        foreach ($this->formFields as $f) {
            $name = $f['name'];
            if (!array_key_exists($name, $_POST)) {
                continue;
            }
            $val = $_POST[$name];
            $val = is_string($val) ? trim($val) : $val;
            $data[$name] = match ($f['type'] ?? 'text') {
                'number' => $val === '' ? null : (float)$val,
                'date'    => $val,
                'textarea', 'text' => $val,
                'select'  => $val,
                default   => $val,
            };
        }
        return $data;
    }

    protected function validate(array $data, bool $isUpdate, int $id = 0): array
    {
        $errors = [];
        foreach ($this->formFields as $f) {
            $name = $f['name'];
            if (!empty($f['required'])) {
                $val = $data[$name] ?? '';
                if ($val === '' || $val === null) {
                    $errors[] = ($f['label'] ?? $name) . '不能为空';
                }
            }
            // 唯一性校验
            if (!empty($f['unique'])) {
                $val = $data[$name] ?? '';
                if ($val !== '' && $val !== null) {
                    $sql = "SELECT id FROM {$this->table} WHERE `$name` = :v";
                    $p = ['v' => $val];
                    if ($isUpdate) {
                        $sql .= ' AND id <> :id';
                        $p['id'] = $id;
                    }
                    if ($this->db->fetch($sql, $p)) {
                        $errors[] = ($f['label'] ?? $name) . '已存在';
                    }
                }
            }
        }
        return $errors;
    }

    protected function siteName(): string
    {
        $s = $this->db->fetch("SELECT value FROM settings WHERE `key` = 'site_name'");
        return $s['value'] ?? '工厂仓库 ERP 管理系统';
    }
}
