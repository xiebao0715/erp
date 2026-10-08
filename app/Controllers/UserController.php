<?php
/**
 * 成员账号管理 + 权限设置（仅管理员）
 * - 列表 / 新增 / 编辑 / 删除（弹窗表单 + 二次确认）
 * - 权限设置弹窗（模块级 + 操作级复选框）
 * - 管理员不可删除；不可删除自己
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;
use Core\Csrf;
use Core\Permission;

final class UserController extends BaseController
{
    protected string $module = 'user';

    public function index(): void
    {
        $this->requireAdmin();

        $search = trim((string)input('q', ''));
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (username LIKE :q1 OR name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $search . '%';
        }

        $countSql = "SELECT COUNT(*) FROM users WHERE $where";
        $listSql  = "SELECT id,username,name,is_admin,phone,status,last_login,created_at FROM users WHERE $where ORDER BY is_admin DESC, id ASC";
        $page = $this->listData($countSql, $listSql, $params, 15);
        $page['search'] = $search;

        $this->view('users/index', [
            'title'  => '成员管理',
            'page'   => $page,
            'modules' => Permission::MODULES,
            'actions' => Permission::ACTIONS,
            'moduleActions' => Permission::MODULE_ACTIONS,
        ]);
    }

    /** 新增（AJAX） */
    public function store(): void
    {
        $this->requireAdmin();
        $data = $this->collectInput(false);
        $errors = $this->validate($data, false);
        if ($errors) $this->fail(implode('；', $errors));

        try {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            $data['permissions'] = null;
            $this->db->insert('users', $data);
            $this->ok('添加成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 编辑-取记录（AJAX） */
    public function edit(): void
    {
        $this->requireAdmin();
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id,username,name,phone,is_admin,status FROM users WHERE id=:id", ['id' => $id]);
        if (!$row) $this->fail('记录不存在');
        $this->json($row);
    }

    /** 详情数据（AJAX，不含密码） */
    public function detail(): void
    {
        $this->requireAdmin();
        $id = (int)input('id', 0);
        $row = $this->db->fetch(
            "SELECT id,username,name,phone,is_admin,status,last_login,created_at FROM users WHERE id=:id",
            ['id' => $id]
        );
        if (!$row) $this->fail('记录不存在');
        $this->json($row);
    }

    /** 更新（AJAX） */
    public function update(): void
    {
        $this->requireAdmin();
        $id = (int)input('id', 0);
        $exists = $this->db->fetch("SELECT id,is_admin FROM users WHERE id=:id", ['id' => $id]);
        if (!$exists) $this->fail('记录不存在');

        $data = $this->collectInput(true);
        $errors = $this->validate($data, true, $id);
        if ($errors) $this->fail(implode('；', $errors));

        try {
            // 管理员身份不可被取消
            if ((int)$exists['is_admin'] === 1) {
                $data['is_admin'] = 1;
            }
            // 密码留空则不修改
            if (isset($data['password']) && $data['password'] === '') {
                unset($data['password']);
            } elseif (isset($data['password']) && $data['password'] !== '') {
                $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            $this->db->update('users', $data, 'id = :id', ['id' => $id]);
            $this->ok('编辑成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 删除（AJAX） */
    public function destroy(): void
    {
        $this->requireAdmin();
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id,is_admin,username FROM users WHERE id=:id", ['id' => $id]);
        if (!$row) $this->fail('记录不存在');
        if ((int)$row['is_admin'] === 1) $this->fail('管理员账号不可删除');
        if ((int)$id === Auth::instance()->id()) $this->fail('不能删除当前登录账号');

        try {
            $this->db->delete('users', 'id = :id', ['id' => $id]);
            $this->ok('删除成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 打印成员列表 */
    public function print(): void
    {
        $this->requireAdmin();
        $search = trim((string)input('q', ''));
        $where = '1=1';
        $params = [];
        if ($search !== '') {
            $where .= ' AND (username LIKE :q1 OR name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $search . '%';
        }
        $items = $this->db->fetchAll("SELECT id,username,name,is_admin,phone,status,last_login,created_at FROM users WHERE $where ORDER BY is_admin DESC, id ASC", $params);
        echo $this->render('users/print', ['title' => '成员管理', 'items' => $items]);
    }

    /** 权限设置-取当前权限（AJAX） */
    public function permissions(): void
    {
        $this->requireAdmin();
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id,username,name,is_admin,permissions FROM users WHERE id=:id", ['id' => $id]);
        if (!$row) $this->fail('记录不存在');
        $perms = [];
        if ($row['permissions']) {
            $decoded = json_decode($row['permissions'], true);
            if (is_array($decoded)) $perms = $decoded;
        }
        $this->json([
            'user'  => ['id' => $row['id'], 'username' => $row['username'], 'name' => $row['name'], 'is_admin' => (int)$row['is_admin']],
            'perms' => $perms,
            'modules' => Permission::MODULES,
            'actions' => Permission::ACTIONS,
            'moduleActions' => Permission::MODULE_ACTIONS,
        ]);
    }

    /** 保存权限（AJAX）：记录权限分配日志（前后权限 + 操作人 + 变更摘要） */
    public function savePermissions(): void
    {
        $this->requireAdmin();
        Csrf::verify();
        $id = (int)input('id', 0);
        $row = $this->db->fetch("SELECT id,is_admin,name,username,permissions FROM users WHERE id=:id", ['id' => $id]);
        if (!$row) $this->fail('记录不存在');
        if ((int)$row['is_admin'] === 1) $this->fail('管理员拥有全部权限，无需设置');

        $raw = $_POST['perms'] ?? '';
        $perms = [];
        if (is_string($raw)) {
            // 期望 JSON 字符串
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $perms = Permission::normalize($decoded);
            }
        } elseif (is_array($raw)) {
            $perms = Permission::normalize($raw);
        }

        try {
            $oldPerms = [];
            if ($row['permissions']) {
                $decoded = json_decode($row['permissions'], true);
                if (is_array($decoded)) $oldPerms = $decoded;
            }
            $this->db->update('users', ['permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE)], 'id = :id', ['id' => $id]);
            // 权限分配日志
            $auth = Auth::instance();
            $this->db->insert('permission_logs', [
                'user_id'       => $id,
                'user_name'     => ($row['name'] !== '' ? $row['name'] : $row['username']),
                'operator_id'   => $auth->id(),
                'operator_name' => $auth->name(),
                'changes'       => $this->permDiffText($oldPerms, $perms),
                'old_perms'     => json_encode($oldPerms, JSON_UNESCAPED_UNICODE),
                'new_perms'     => json_encode($perms, JSON_UNESCAPED_UNICODE),
            ]);
            $this->ok('权限设置成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 权限分配日志（AJAX，仅管理员） */
    public function permLogs(): void
    {
        $this->requireAdmin();
        $id = (int)input('id', 0);
        if (!$this->db->fetch("SELECT id FROM users WHERE id=:id", ['id' => $id])) $this->fail('记录不存在');
        $logs = $this->db->fetchAll(
            "SELECT operator_name, `changes`, created_at FROM permission_logs WHERE user_id = :id ORDER BY id DESC LIMIT 50",
            ['id' => $id]
        );
        $this->json(['success' => true, 'logs' => $logs]);
    }

    /** 生成权限变更摘要文本（按模块列出新增/移除的操作） */
    private function permDiffText(array $old, array $new): string
    {
        $lines = [];
        foreach (Permission::MODULES as $m => $mlabel) {
            $o = $old[$m] ?? [];
            $n = $new[$m] ?? [];
            $add = array_diff($n, $o);
            $del = array_diff($o, $n);
            if (!$add && !$del) continue;
            $name = fn(string $a): string => Permission::ACTIONS[$a] ?? $a;
            $parts = [];
            if ($add) $parts[] = '新增[' . implode('、', array_map($name, $add)) . ']';
            if ($del) $parts[] = '移除[' . implode('、', array_map($name, $del)) . ']';
            $lines[] = $mlabel . '：' . implode('，', $parts);
        }
        return $lines ? implode("\n", $lines) : '无变化';
    }

    // ---- 内部 ----
    private function requireAdmin(): void
    {
        if (!Auth::instance()->isAdmin()) {
            $this->deny();
        }
    }

    private function collectInput(bool $isUpdate): array
    {
        $data = [
            'username' => trim((string)input('username', '')),
            'name'     => trim((string)input('name', '')),
            'phone'    => trim((string)input('phone', '')),
            'is_admin' => (int)input('is_admin', 0),
            'status'   => (int)input('status', 1),
        ];
        $password = (string)input('password', '');
        if (!$isUpdate || $password !== '') {
            $data['password'] = $password;
        } else {
            $data['password'] = '';
        }
        return $data;
    }

    private function validate(array $data, bool $isUpdate, int $id = 0): array
    {
        $errors = [];
        if (mb_strlen($data['username']) < 3) $errors[] = '用户名至少 3 个字符';
        // 唯一性
        $sql = "SELECT id FROM users WHERE username=:u";
        $p = ['u' => $data['username']];
        if ($isUpdate) { $sql .= ' AND id<>:id'; $p['id'] = $id; }
        if ($this->db->fetch($sql, $p)) $errors[] = '用户名已存在';
        if ($data['name'] === '') $errors[] = '姓名不能为空';
        // 密码：新增必填；编辑留空则不校验
        if (!$isUpdate && mb_strlen($data['password']) < 6) $errors[] = '密码至少 6 位';
        if ($isUpdate && $data['password'] !== '' && mb_strlen($data['password']) < 6) $errors[] = '密码至少 6 位';
        return $errors;
    }
}
