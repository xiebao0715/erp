<?php
/**
 * 认证控制器：登录 / 退出 / 权限拒绝页
 * login() 同时处理 GET（显示表单）与 POST（提交登录）
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\BaseController;
use Core\Auth;
use Core\Csrf;

final class AuthController extends BaseController
{
    protected string $module = ''; // 公开控制器，不走权限校验

    /** 登录 */
    public function login(): void
    {
        $auth = Auth::instance();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // POST：处理登录
            Csrf::verify();
            $username = trim((string)input('username', ''));
            $password = (string)input('password', '');

            if ($username === '' || $password === '') {
                $this->flash('error', '请输入用户名和密码');
                redirect(url('auth/login'));
            }

            if ($auth->attempt($username, $password)) {
                try {
                    $this->db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $auth->id()]);
                } catch (\Throwable $e) {}
                $this->flash('success', '登录成功，欢迎回来！');
                redirect(url('dashboard/index'));
            }
            $this->flash('error', '用户名或密码错误，或账号已被禁用');
            redirect(url('auth/login'));
        }

        // GET：显示登录页（独立布局）
        if ($auth->check()) {
            redirect(url('dashboard/index'));
        }
        echo $this->render('auth/login', ['csrf' => Csrf::token()]);
    }

    /** 退出 */
    public function logout(): void
    {
        Auth::instance()->logout();
        redirect(url('auth/login'));
    }

    /** 权限不足提示页 */
    public function denied(): void
    {
        $this->view('auth/denied', ['title' => '权限不足']);
    }
}
