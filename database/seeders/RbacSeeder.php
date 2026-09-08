<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 当前页面及 API 权限目录：模块 → 页面 → 操作/统计权限。
 * 按 code 更新，不重置普通角色授权、已有用户密码或账号状态。
 */
class RbacSeeder extends Seeder
{
    private const DASHBOARD_ACTIONS = [
        'overview' => ['Key Metrics and Payment Status', '核心指标与付款状态'],
        'sales_trend' => ['Sales Trend', '销售趋势'],
        'categories' => ['Order Categories', '订单分类'],
        'influencers' => ['Influencer Ranking', '达人排行'],
        'staff' => ['Staff Performance', '客服业绩'],
        'recent_orders' => ['Recent Orders', '最近订单'],
        'currencies' => ['Currency Statistics', '币种统计'],
        'paypal' => ['PayPal Income and Expenses', 'PayPal 收支'],
        'exchange_rates' => ['Exchange Rates', '汇率参考'],
        'system_status' => ['Data Coverage Status', '数据覆盖状态'],
        'spreadsheets' => ['Online Spreadsheets', '在线表格'],
    ];

    /** 沿用 system.order.*，保留已有角色关联的权限 ID。 */
    private const ORDER_ACTIONS = [
        'list' => ['View Orders', '查询订单'],
        'create' => ['Create Order', '创建订单'],
        'update' => ['Update Staff Allocation / Complete Order', '调整订单客服 / 确认完成'],
        'delete' => ['Delete Order', '删除订单'],
        'export' => ['Export Orders', '导出订单'],
        'testing' => ['View Testing Orders', '查看测试订单'],
        'statistics.overview' => ['Sales Overview', '成交概览'],
        'statistics.currencies' => ['Currency Summary', '币种汇总'],
        'statistics.sales-trend' => ['Sales Trend', '销售趋势'],
        'statistics.categories' => ['Sales Categories', '销售分类'],
        'statistics.influencers' => ['Influencer Ranking', '达人排行'],
        'statistics.staff' => ['Staff Allocation Statistics', '客服分摊统计'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $roles = $this->seedRoles();
            $homeId = $this->saveMenu('dashboard', 'Home', '首页', '/dashboard', 'Layout', 0, 1, 10, 'HomeOutlined');
            $businessId = $this->saveMenu('business', 'Business Management', '业务管理', '/workbench', 'Layout', 0, 1, 20, 'ShoppingCartOutlined');
            $systemId = $this->saveMenu('system', 'System Management', '系统管理', '/system', 'Layout', 0, 1, 100, 'SettingOutlined');

            $overviewId = $this->saveMenu('dashboard.overview', 'Home', '首页', '/dashboard/overview', 'dashboard/index', $homeId, 2, 10, 'HomeOutlined');
            $this->saveActions($overviewId, 'dashboard.overview', self::DASHBOARD_ACTIONS);
            // 保留权限 code 和 ID，仅调整导航归属，避免已有角色丢失订单授权。
            $ordersId = $this->saveMenu('dashboard.order_management', 'Order Management', '订单管理', '/workbench/order-management', 'workbench/order-management/index', $businessId, 2, 0, 'ShoppingCartOutlined');
            $this->saveActions($ordersId, 'system.order', self::ORDER_ACTIONS);
            $this->seedBusinessPages($businessId);
            $this->seedSystemPages($systemId);

            // 旧版订单入口已合并到订单管理；保留记录和授权关联以便追溯。
            DB::table('permissions')->whereIn('code', ['orders', 'orders.list', 'dashboard.view'])->update([
                'status' => 0,
                'hidden' => true,
                'is_menu_visible' => false,
                'updated_at' => now(),
            ]);

            $allIds = DB::table('permissions')->where('status', 1)->whereNull('deleted_at')->pluck('id')->all();
            $this->grantPermissions($roles['super_admin']['id'], $allIds);

            // 默认 viewer 仅在首次创建时获得首页只读权限，重跑不覆盖授权调整。
            if ($roles['viewer']['created']) {
                $viewerCodes = ['dashboard', 'dashboard.overview'];
                foreach (array_keys(self::DASHBOARD_ACTIONS) as $action) {
                    $viewerCodes[] = 'dashboard.overview.' . $action;
                }
                $this->grantPermissions($roles['viewer']['id'], DB::table('permissions')->whereIn('code', $viewerCodes)->pluck('id')->all());
            }
            $this->ensureSuperAdmin($roles['super_admin']['id']);
        });

        $counts = DB::table('permissions')->where('status', 1)->whereNull('deleted_at')
            ->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $this->command?->info(sprintf('RBAC: %d 个菜单，%d 个操作权限；已有普通角色授权已保留。', $counts['menu'] ?? 0, $counts['action'] ?? 0));
    }

    /** 以 code 定位内置角色，避免覆盖同 ID 的自定义角色。 */
    private function seedRoles(): array
    {
        $roles = [];
        foreach ([
            'super_admin' => ['Super Administrator', '超级管理员', 0],
            'admin' => ['Administrator', '管理员', 10],
            'viewer' => ['Read-only Viewer', '只读用户', 90],
        ] as $code => [$name, $nameZh, $sort]) {
            $existing = DB::table('roles')->where('code', $code)->first();
            $roles[$code] = [
                'id' => $existing?->id ?? DB::table('roles')->insertGetId([
                    'code' => $code,
                    'name' => $name,
                    'name_zh' => $nameZh,
                    'status' => 1,
                    'is_system' => true,
                    'sort' => $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]),
                'created' => $existing === null,
            ];
        }

        return $roles;
    }

    /** 七个业务页面及页面内操作。 */
    private function seedBusinessPages(int $parentId): void
    {
        $pages = [
            'invoice' => ['Invoice Orders', 'Invoice 订单', 'invoice', [
                'list' => ['View Invoices', '查询订单'],
                'create' => ['Create Invoice', '录入订单'],
                'update' => ['Update Invoice', '编辑订单'],
                'delete' => ['Delete Invoice', '删除订单'],
                'ocr' => ['Recognize Screenshot', '截图识别'],
                'logs' => ['View Operation Logs', '操作记录'],
                'export' => ['Export Operation Logs', '导出操作记录'],
            ]],
            'sa_sales' => ['SA Sales Analysis', 'SA 销售分析', 'sa-sales', [
                'list' => ['View Sales Report', '查看销售报表'],
                'export' => ['Export Sales Report', '导出销售报表'],
            ]],
            'procurement' => ['Procurement Workbench', '采购工作台', 'procurement', [
                'list' => ['View Procurement Tasks', '查询采购'],
                'statistics' => ['Procurement Statistics', '采购统计'],
                'create' => ['Create Procurement Task', '创建采购任务'],
                'update' => ['Update Procurement Task', '更新采购任务'],
                'delete' => ['Remove Procurement Task', '移除采购任务'],
                'logs' => ['View Operation Logs', '操作记录'],
                'export' => ['Export Procurement and Logs', '导出采购及日志'],
            ]],
            'warehouse' => ['Warehouse Workbench', '仓库工作台', 'warehouse', [
                'list' => ['View Warehouse Records', '查询仓库'],
                'update' => ['Inspect and Ship', '质检与发货'],
            ]],
            'influencer' => ['Influencer Workbench', '达人工作台', 'influencer', [
                'list' => ['View Website Directory', '查看网站目录'],
                'statistics' => ['Sales Ranking', '销售排行'],
                'create' => ['Assign Website', '添加网站归属'],
                'export' => ['Export Website Directory', '导出网站目录'],
            ]],
            'paypal' => ['PayPal Balance Monitor', 'PayPal 余额监控', 'paypal', [
                'list' => ['View Accounts', '查看账户'],
                'orders' => ['View Received Orders', '查看收款订单'],
                'orders_export' => ['Export Received Orders', '下载收款订单'],
                'withdrawals' => ['View Withdrawal Records', '查看提款记录'],
                'statistics' => ['View Withdrawal Statistics', '查看提款统计'],
                'logs' => ['Download Change Log', '下载修改日志'],
                'create' => ['Create Account', '新增账户'],
                'balance' => ['Correct Balance', '修正余额'],
                'review' => ['Update Review Count', '更新审核次数'],
                'withdrawal' => ['Record Withdrawal', '记录提现'],
                'export' => ['Export Account Records', '导出账户记录'],
            ]],
            'operations' => ['Operations Inspection', '工作巡查', 'operations', [
                'list' => ['View Online Spreadsheet Directory', '查看在线表格目录'],
            ]],
        ];

        $sort = 10;
        foreach ($pages as $key => [$name, $nameZh, $path, $actions]) {
            $code = 'business.' . $key;
            $menuId = $this->saveMenu($code, $name, $nameZh, '/workbench/' . $path, 'workbench/' . $path . '/index', $parentId, 2, $sort);
            $this->saveActions($menuId, $code, $actions);
            $sort += 10;
        }
    }

    /** 系统组件名兼容现有 saveb-admin 的 menu-mapper。 */
    private function seedSystemPages(int $parentId): void
    {
        $pages = [
            'user' => ['User Management', '用户管理', 'users', 'UserList', [
                'list' => ['View Users', '查看用户'],
                'create' => ['Create User', '新建用户'],
                'update' => ['Update User', '更新用户'],
                'delete' => ['Delete User', '删除用户'],
                'assign_role' => ['Assign User Roles', '分配用户角色'],
            ]],
            'role' => ['Role Management', '角色管理', 'roles', 'RoleList', [
                'list' => ['View Roles', '查看角色'],
                'create' => ['Create Role', '新建角色'],
                'update' => ['Update Role', '更新角色'],
                'delete' => ['Delete Role', '删除角色'],
                'assign_permission' => ['Assign Permissions', '分配权限'],
            ]],
            'permission' => ['Permission Management', '权限管理', 'permissions', 'PermissionList', [
                'read' => ['View Permissions', '查看权限'],
                'create' => ['Create Permission', '新建权限'],
                'update' => ['Update Permission', '更新权限'],
                'delete' => ['Delete Permission', '删除权限'],
            ]],
        ];

        $sort = 10;
        foreach ($pages as $key => [$name, $nameZh, $path, $component, $actions]) {
            $code = 'system.' . $key;
            $menuId = $this->saveMenu($code, $name, $nameZh, '/system/' . $path, 'system/' . $component, $parentId, 2, $sort);
            $this->saveActions($menuId, $code, $actions);
            $sort += 10;
        }
    }

    /** 更新页面位置和双语名称，保留 ID、启用及软删除状态。 */
    private function saveMenu(string $code, string $name, string $nameZh, string $path, string $component, int $parentId, int $level, int $sort, ?string $icon = null): int
    {
        return $this->savePermission($code, [
            'name' => $name,
            'name_zh' => $nameZh,
            'parent_id' => $parentId,
            'type' => 'menu',
            'path' => $path,
            'component' => $component,
            'icon' => $icon,
            'level' => $level,
            'sort' => $sort,
            'is_menu_visible' => true,
            'action' => 'custom',
        ]);
    }

    /** 权限 code 与路由中间件保持一致，所有操作叶子归属其页面。 */
    private function saveActions(int $parentId, string $prefix, array $actions): void
    {
        $sort = 10;
        foreach ($actions as $action => [$name, $nameZh]) {
            $actionType = in_array($action, ['list', 'create', 'update', 'delete', 'export'], true) ? $action : 'custom';
            $this->savePermission($prefix . '.' . $action, [
                'parent_id' => $parentId,
                'name' => $name,
                'name_zh' => $nameZh,
                'type' => 'action',
                'action' => $action === 'read' ? 'list' : $actionType,
                'resource' => $prefix === 'system.order' ? 'orders' : $prefix,
                'level' => 3,
                'sort' => $sort,
                'is_menu_visible' => false,
                'path' => null,
                'component' => null,
            ]);
            $sort += 10;
        }
    }

    private function savePermission(string $code, array $attributes): int
    {
        $existingId = DB::table('permissions')->where('code', $code)->value('id');
        $attributes['updated_at'] = now();
        if ($existingId !== null) {
            DB::table('permissions')->where('id', $existingId)->update($attributes);

            return (int) $existingId;
        }

        return DB::table('permissions')->insertGetId($attributes + [
            'code' => $code,
            'status' => 1,
            'hidden' => false,
            'created_at' => now(),
        ]);
    }

    /** 仅补充缺失关联，不清空已有授权。 */
    private function grantPermissions(int $roleId, array $permissionIds): void
    {
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** 仅创建缺失账号，重跑不提升已有同名用户权限或重置密码。 */
    private function ensureSuperAdmin(int $roleId): void
    {
        $username = 'super_admin';
        if (DB::table('users')->whereRaw('lower(username) = ?', [$username])->exists()) {
            return;
        }

        $password = config('rbac.seed_admin_password');
        if (!$password) {
            throw new \RuntimeException('非本地环境请设置 RBAC_SEED_ADMIN_PASSWORD 后再创建初始管理员。');
        }

        $userId = DB::table('users')->insertGetId([
            'username' => $username,
            'password_hash' => Hash::make($password),
            'display_name' => 'Super Administrator',
            'role_id' => $roleId,
            'active' => true,
            'must_change_password' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->command?->info('已创建初始管理员 super_admin，首次登录需修改密码。');
    }
}
