<?php
/**
 * 系统设置
 * - index: 显示与编辑 settings 表键值（站点名/公司信息/库存阈值/打印抬头等）
 * - update: 保存（AJAX）
 * - uploadLoginBg / toggleLoginBg / clearLoginBg: 登录页自定义背景管理
 */
declare(strict_types=1);

namespace App\Controllers;

use Core\Auth;
use Core\BaseController;
use Core\Csrf;

final class SettingsController extends BaseController
{
    protected string $module = 'settings';

    /** 登录背景专用键（不在通用设置表格中显示，由专用界面管理） */
    private const LOGIN_BG_KEYS = ['login_bg', 'login_bg_enabled'];

    /** 列表 + 编辑 */
    public function index(): void
    {
        $rows = $this->db->fetchAll("SELECT `key`, value, label FROM settings ORDER BY id ASC");
        $generic = [];
        $loginBg = '';
        $loginBgEnabled = false;
        foreach ($rows as $r) {
            if (in_array((string)$r['key'], self::LOGIN_BG_KEYS, true)) {
                if ($r['key'] === 'login_bg') {
                    $loginBg = (string)$r['value'];
                } else {
                    $loginBgEnabled = ((string)$r['value']) === '1';
                }
                continue;
            }
            $generic[] = $r;
        }
        $this->view('settings/index', [
            'title'          => '系统设置',
            'rows'           => $generic,
            'loginBg'        => $loginBg,
            'loginBgEnabled' => $loginBgEnabled,
        ]);
    }

    /** 保存（AJAX）——方法名 update 对应权限动作「编辑」 */
    public function update(): void
    {
        $settings = $_POST['settings'] ?? [];
        if (!is_array($settings)) {
            $this->fail('数据格式错误');
        }
        if (empty($settings)) {
            $this->fail('没有要保存的设置项');
        }
        try {
            $this->db->transaction(function ($db) use ($settings) {
                foreach ($settings as $key => $value) {
                    if (!is_string($key)) continue;
                    $key = trim($key);
                    $value = trim((string)$value);
                    if ($key === '') continue;
                    // 登录背景由专用接口管理；结构版本键不允许修改
                    if (in_array($key, self::LOGIN_BG_KEYS, true) || $key === 'schema_version') continue;
                    $exists = $db->fetch("SELECT id FROM settings WHERE `key`=:k", ['k' => $key]);
                    if ($exists) {
                        $db->update('settings', ['value' => $value], 'id = :id', ['id' => $exists['id']]);
                    } else {
                        $db->insert('settings', ['key' => $key, 'value' => $value, 'label' => $key]);
                    }
                }
            });
            $this->ok('保存成功');
        } catch (\Throwable $e) {
            $this->fail('操作失败，请重试');
        }
    }

    /** 上传登录背景图片（更换时自动删除旧图片；开启状态下上传后立即生效） */
    public function uploadLoginBg(): void
    {
        try {
            $file = $_FILES['bg_file'] ?? null;
            if (!is_array($file)) {
                $this->fail('请选择要上传的图片文件');
            }
            $errCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
                $this->fail('图片超过服务器上传限制，请压缩后再上传');
            }
            if ($errCode !== UPLOAD_ERR_OK) {
                $this->fail('请选择要上传的图片文件');
            }
            if ((int)($file['size'] ?? 0) > 8 * 1024 * 1024) {
                $this->fail('图片大小不能超过 8MB');
            }
            $info = @getimagesize((string)$file['tmp_name']);
            if ($info === false) {
                $this->fail('文件不是有效的图片');
            }
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $ext = $extMap[(string)($info['mime'] ?? '')] ?? '';
            if ($ext === '') {
                $this->fail('仅支持 JPG / PNG / GIF / WEBP 格式图片');
            }
            $dir = PUBLIC_PATH . '/uploads/login_bg';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->fail('创建上传目录失败，请联系管理员检查 public 目录写入权限');
            }
            $name = 'login_bg_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $name)) {
                $this->fail('保存图片失败，请检查 public/uploads 目录写入权限');
            }
            $rel = 'uploads/login_bg/' . $name;
            $old = setting('login_bg', '');
            $this->saveSetting('login_bg', $rel);
            // 更换背景：自动删除旧背景图片
            if ($old !== '' && $old !== $rel) {
                $this->deleteBgFile($old);
            }
            $this->ok('背景图片上传成功', ['url' => asset($rel)]);
        } catch (\Throwable $e) {
            $this->fail('上传失败：' . $e->getMessage());
        }
    }

    /** 开启/关闭登录自定义背景（关闭时保留图片，重新开启可直接使用） */
    public function toggleLoginBg(): void
    {
        $enabled = ((string)($_POST['enabled'] ?? '')) === '1' ? '1' : '0';
        $this->saveSetting('login_bg_enabled', $enabled);
        $this->ok($enabled === '1' ? '已开启登录自定义背景' : '已关闭登录自定义背景（背景图片已保留）');
    }

    /** 清除登录背景（删除图片文件并恢复默认背景，即「不设置」） */
    public function clearLoginBg(): void
    {
        $old = setting('login_bg', '');
        $this->saveSetting('login_bg', '');
        $this->saveSetting('login_bg_enabled', '0');
        if ($old !== '') {
            $this->deleteBgFile($old);
        }
        $this->ok('已清除登录背景，恢复默认');
    }

    /** public 目录绝对路径 */
    private function publicPath(string $rel = ''): string
    {
        $base = dirname(__DIR__, 2) . '/public';
        return $rel === '' ? $base : $base . '/' . ltrim($rel, '/');
    }

    /** 写入/更新单个设置键 */
    private function saveSetting(string $key, string $value): void
    {
        $exists = $this->db->fetch("SELECT id FROM settings WHERE `key`=:k", ['k' => $key]);
        if ($exists) {
            $this->db->update('settings', ['value' => $value], 'id = :id', ['id' => $exists['id']]);
        } else {
            $this->db->insert('settings', ['key' => $key, 'value' => $value, 'label' => $key]);
        }
    }

    /** 删除背景图片文件（仅允许删除登录背景目录内的文件） */
    private function deleteBgFile(string $rel): void
    {
        if (!str_starts_with($rel, 'uploads/login_bg/')) {
            return;
        }
        $file = $this->publicPath($rel);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
