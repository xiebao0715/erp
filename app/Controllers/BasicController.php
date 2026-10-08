<?php
/**
 * 基础设置
 * - 商品分类：列表 / 新增 / 编辑 / 删除（独立页面）
 * - 商品单位：列表 / 新增 / 编辑 / 删除
 * 写操作的 CSRF 校验由 BaseController::_before 统一完成
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;

final class BasicController extends BaseController
{
    protected string $module = 'basic';

    /** 设置页：分类 + 单位 */
    public function index(): void
    {
        $isAdmin = Auth::instance()->isAdmin();
        $this->view('basic/index', [
            'title'      => '基础设置',
            'categories' => $this->db->fetchAll("SELECT * FROM product_categories ORDER BY sort ASC, id ASC"),
            'units'      => $this->db->fetchAll("SELECT * FROM product_units ORDER BY sort ASC, id ASC"),
            'productUsed' => $this->unitUsage(),
            'canCreate'  => $isAdmin ?: \Core\Permission::check($this->module, 'create'),
            'canEdit'    => $isAdmin ?: \Core\Permission::check($this->module, 'edit'),
            'canDelete'  => $isAdmin ?: \Core\Permission::check($this->module, 'delete'),
        ]);
    }

    // ---------------- 商品分类 ----------------

    public function categoryStore(): void
    {
        [$name, $sort] = $this->readNameSort(60, '分类名称');
        if ($this->db->fetch("SELECT id FROM product_categories WHERE name = :n", ['n' => $name])) {
            $this->fail('分类名称已存在');
        }
        try {
            $this->db->insert('product_categories', ['name' => $name, 'sort' => $sort]);
            $this->ok('添加成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    public function categoryUpdate(): void
    {
        $id = (int)input('id', 0);
        if (!$this->db->fetch("SELECT id FROM product_categories WHERE id = :id", ['id' => $id])) {
            $this->fail('分类不存在');
        }
        [$name, $sort] = $this->readNameSort(60, '分类名称');
        $dup = $this->db->fetch(
            "SELECT id FROM product_categories WHERE name = :n AND id <> :id",
            ['n' => $name, 'id' => $id]
        );
        if ($dup) {
            $this->fail('分类名称已存在');
        }
        try {
            $this->db->update('product_categories', ['name' => $name, 'sort' => $sort], 'id = :id', ['id' => $id]);
            $this->ok('编辑成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    public function categoryDestroy(): void
    {
        $id = (int)input('id', 0);
        if (!$this->db->fetch("SELECT id FROM product_categories WHERE id = :id", ['id' => $id])) {
            $this->fail('分类不存在');
        }
        $used = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM products WHERE category_id = :id",
            ['id' => $id]
        );
        if ($used > 0) {
            $this->fail("该分类下有 {$used} 个商品，无法删除；请先移除或转移商品分类");
        }
        try {
            $this->db->delete('product_categories', 'id = :id', ['id' => $id]);
            $this->ok('删除成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    // ---------------- 商品单位 ----------------

    public function unitStore(): void
    {
        [$name, $sort] = $this->readNameSort(10, '单位名称');
        if ($this->db->fetch("SELECT id FROM product_units WHERE name = :n", ['n' => $name])) {
            $this->fail('单位名称已存在');
        }
        try {
            $this->db->insert('product_units', ['name' => $name, 'sort' => $sort]);
            $this->ok('添加成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    public function unitUpdate(): void
    {
        $id = (int)input('id', 0);
        if (!$this->db->fetch("SELECT id FROM product_units WHERE id = :id", ['id' => $id])) {
            $this->fail('单位不存在');
        }
        [$name, $sort] = $this->readNameSort(10, '单位名称');
        $dup = $this->db->fetch(
            "SELECT id FROM product_units WHERE name = :n AND id <> :id",
            ['n' => $name, 'id' => $id]
        );
        if ($dup) {
            $this->fail('单位名称已存在');
        }
        try {
            $this->db->update('product_units', ['name' => $name, 'sort' => $sort], 'id = :id', ['id' => $id]);
            $this->ok('编辑成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    public function unitDestroy(): void
    {
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id, name FROM product_units WHERE id = :id", ['id' => $id]);
        if (!$row) {
            $this->fail('单位不存在');
        }
        $used = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM products WHERE unit = :n",
            ['n' => $row['name']]
        );
        if ($used > 0) {
            $this->fail("有 {$used} 个商品正在使用单位「{$row['name']}」，无法删除");
        }
        try {
            $this->db->delete('product_units', 'id = :id', ['id' => $id]);
            $this->ok('删除成功');
        } catch (\Throwable) {
            $this->fail('操作失败，请重试');
        }
    }

    // ---------------- 内部 ----------------

    /** 读取并校验 name / sort，失败直接输出错误 JSON */
    private function readNameSort(int $maxLen, string $label): array
    {
        $name = trim((string)input('name', ''));
        $sort = (int)input('sort', 0);
        if ($name === '') {
            $this->fail($label . '不能为空');
        }
        if (mb_strlen($name) > $maxLen) {
            $this->fail($label . "不能超过 {$maxLen} 个字符");
        }
        return [$name, $sort];
    }

    /** 单位 → 使用中的商品数量（页面提示用） */
    private function unitUsage(): array
    {
        $map = [];
        $rows = $this->db->fetchAll(
            "SELECT unit, COUNT(*) AS c FROM products WHERE unit <> '' GROUP BY unit"
        );
        foreach ($rows as $r) {
            $map[$r['unit']] = (int)$r['c'];
        }
        return $map;
    }
}
