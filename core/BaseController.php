<?php
/**
 * 控制器基类：统一渲染、CSRF、权限校验、分页
 */
declare(strict_types=1);

namespace Core;

abstract class BaseController
{
    protected Database $db;

    /** 当前控制器对应的权限模块 key，子类覆盖 */
    protected string $module = '';

    /** 子类可覆盖：方法名 → 权限动作 的控制器级覆盖（合并进 METHOD_ACTION_MAP） */
    protected array $actionMap = [];

    /** 方法名 → 权限操作 映射 */
    private const METHOD_ACTION_MAP = [
        'index'   => 'view',
        'list'    => 'view',
        'show'    => 'view',
        'detail'  => 'view',
        'search'  => 'view',
        'data'    => 'view',
        'create'  => 'create',
        'store'   => 'create',
        'edit'    => 'edit',
        'update'  => 'edit',
        'destroy' => 'delete',
        'delete'  => 'delete',
        'print'   => 'print',
        // 扩展方法（基础设置：分类/单位；库存预警；权限设置等）
        'categoryStore'   => 'create',
        'categoryUpdate'  => 'edit',
        'categoryDestroy' => 'delete',
        'unitStore'       => 'create',
        'unitUpdate'      => 'edit',
        'unitDestroy'     => 'delete',
        'warning'         => 'view',
        'printWarning'    => 'print',
        'permissions'     => 'view',
        'savePermissions' => 'edit',
        // 商品库存查询（出库/退回/生产表单显示当前库存）
        'stock'           => 'view',
        // 生产管理：生产方案（BOM）维护与查询
        'planStore'       => 'create',
        'planUpdate'      => 'edit',
        'planDestroy'     => 'delete',
        'planItems'       => 'view',
        // 单据状态推进：入库/出库待办单「已完成」=完成操作权限；生产「开始生产/完成生产」由生产控制器覆盖为 start
        'complete'        => 'operate',
        'start'           => 'start',
        // 入库退回 / 出库反回（由来源单发起）：取明细=查看，登记=退回/反回独立权限
        'returnItems'     => 'view',
        'returnStore'     => 'return',
        'reverseItems'    => 'view',
        'reverseStore'    => 'reverse',
        // 生产取消：取明细=查看，登记取消=取消登记权限，确认取消（退回材料/扣成品）=退回/返回权限
        'cancelItems'     => 'view',
        'cancelStore'     => 'cancel',
        'cancelComplete'  => 'return',
        // 系统设置：登录背景管理（编辑权限）
        'uploadLoginBg'   => 'edit',
        'toggleLoginBg'   => 'edit',
        'clearLoginBg'    => 'edit',
    ];

    public function __construct()
    {
        $this->db = Database::instance();
    }

    /**
     * 路由前置钩子（由 Bootstrap 调用）
     * - 对写操作（POST/PUT/DELETE 等）校验 CSRF
     * - 按方法名自动校验模块权限
     */
    public function _before(string $method): void
    {
        $auth = Auth::instance();

        // 写操作统一校验 CSRF（login 的 POST 自行调用 verify，但此处对其也安全通过）
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            try {
                Csrf::verify();
            } catch (\Throwable $e) {
                // AJAX 请求（含上传大文件超过 post_max_size 时 POST 被整体丢弃）返回可读提示而非 500
                if (is_ajax()) {
                    json_response(['success' => false, 'message' => '会话已过期或文件超过服务器大小限制，请刷新页面重试（大图请压缩后再上传）']);
                }
                throw $e;
            }
        }

        // 权限校验（仅当控制器声明了 module 且方法命中映射）
        $methodMap = array_merge(self::METHOD_ACTION_MAP, $this->actionMap);
        if ($this->module !== '' && isset($methodMap[$method])) {
            $action = $methodMap[$method];
            if (!$auth->check()) {
                $this->deny();
            }
            // 成员管理仅管理员
            if ($this->module === 'user' && !$auth->isAdmin()) {
                $this->deny();
            } elseif ($this->module !== 'user' && !Permission::check($this->module, $action) && !$this->alternativePermission($method, $action)) {
                $this->deny();
            }
        }
    }

    /** 子类可覆盖：模块+动作权限不足时的兜底判断 */
    protected function alternativePermission(string $method, string $action): bool
    {
        return false;
    }

    /** 权限不足时的处理 */
    protected function deny(): void
    {
        if (is_ajax()) {
            json_response(['success' => false, 'message' => '权限不足'], 403);
        }
        redirect(url('auth/denied'));
    }

    /** 渲染视图（带布局） */
    protected function view(string $template, array $data = []): void
    {
        $data['current_user'] = Auth::instance();
        $data['modules'] = Permission::MODULES;
        $data['actions'] = Permission::ACTIONS;

        // 侧边栏可见模块（受权限控制）
        $visibleModules = [];
        $auth = Auth::instance();
        foreach (Permission::MODULES as $key => $label) {
            if ($auth->isAdmin()) {
                $visibleModules[$key] = $label;
            } elseif (Permission::check($key, 'view')) {
                $visibleModules[$key] = $label;
            }
        }
        // 成员管理仅管理员可见
        if ($auth->isAdmin()) {
            $visibleModules = ['user' => '成员管理'] + $visibleModules;
        }
        $data['visible_modules'] = $visibleModules;

        $content = $this->render($template, $data);
        $data['__content'] = $content;
        extract($data, EXTR_SKIP);

        require VIEW_PATH . '/layout/main.php';
    }

    /** 渲染视图片段（不带布局），返回字符串 */
    protected function render(string $template, array $data = []): string
    {
        $file = VIEW_PATH . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("视图不存在: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string)ob_get_clean();
    }

    /** 渲染部分视图返回 HTML（用于弹窗内容 AJAX 加载） */
    protected function renderPartial(string $template, array $data = []): string
    {
        return $this->render($template, $data);
    }

    /** 分页查询。返回 ['items', 'total', 'page', 'perPage', 'totalPages', 'search'] */
    protected function paginate(string $sqlBuilder, array $params, int $page, int $perPage): array
    {
        // 由子类直接调用 listData 更通用，此处仅保留扩展点
        return [];
    }

    /** 通用分页：传入 countSql / listSql（已含占位符），返回分页结构 */
    protected function listData(string $countSql, string $listSql, array $params, int $perPage = 15): array
    {
        $page = max(1, (int)input('page', 1));
        $offset = ($page - 1) * $perPage;

        $total = (int)$this->db->fetchColumn($countSql, $params);
        $totalPages = $perPage > 0 ? (int)ceil($total / $perPage) : 1;
        if ($page > $totalPages && $total > 0) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $listSql .= ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $items = $this->db->fetchAll($listSql, $params);

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'perPage'     => $perPage,
            'totalPages'  => $totalPages,
        ];
    }

    /** 设置闪存消息 */
    protected function flash(string $type, string $message): void
    {
        $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
    }

    /** 直接输出 JSON（成功响应，可携带任意数据） */
    protected function json(mixed $data, int $code = 200): void
    {
        json_response($data, $code);
    }

    /** 成功 JSON */
    protected function ok(string $message = '操作成功', array $extra = []): void
    {
        json_response(array_merge(['success' => true, 'message' => $message], $extra));
    }

    /** 失败 JSON */
    protected function fail(string $message = '操作失败，请重试', int $code = 200, array $extra = []): void
    {
        json_response(array_merge(['success' => false, 'message' => $message], $extra), $code);
    }
}
