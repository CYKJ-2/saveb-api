<?php

use App\Controllers\AuthController;
use App\Controllers\OrderController;
use App\Controllers\PermissionController;
use App\Controllers\RoleController;
use App\Controllers\UserController;
use App\Controllers\UserRoleController;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/workbench.php';
require __DIR__ . '/dashboard.php';
require __DIR__ . '/collector.php';
require __DIR__ . '/analysis.php';

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
|
| Authentication model:
|   - Login:     POST /api/auth/login            → returns bearer token
|   - Logout:    POST /api/auth/logout           → revokes current token
|   - Me:        GET  /api/auth/me              → current user info with permissions tree
|
| Middleware:
|   - auth.api       requires valid bearer token
|   - role:a,b       restricts to listed roles (check all roles, not just primary)
|   - permission:x   requires the named permission (RBAC, recommended)
|
| RBAC 权限控制策略：
|   ─────────────────
|   1) 推荐使用 permission: 中间件，按权限码精确控制
|      例如 permission:system.user.list 表示需要"查看用户"权限
|      权限码由管理员在 roles 中分配，完全由数据库配置，无需改代码
|
|   2) role: 中间件仅用于粗粒度"角色白名单"场景
|      例如 role:admin,viewer 表示 admin 或 viewer 角色可访问
|      它检查用户的全部角色（role_id + user_roles pivot），
|      super_admin 角色拥有所有权限的 '*' 通配，可绕过任何 role: 检查
|
|   Super-admin (role.code = super_admin) bypasses every permission gate.
|
| Permissions table design (unified):
|   - 原 menus 表已下线，菜单节点与权限点统一在 permissions 表中维护
|   - type = 'menu'  : 导航节点（侧边栏树）
|   - type = 'action': API 权限点（不出现在侧边栏）
|   - 全部存在 permissions 表中，通过 parent_id 自引用树状结构组织
|
*/

// --- Public auth endpoints ---
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
});

// --- Protected routes (require bearer token) ---
Route::middleware('auth.api')->group(function () {

    Route::prefix('order-management')->group(function () {
        Route::get('/editor-options', [\App\Controllers\OrderManagementController::class, 'editorOptions'])->middleware('permission:system.order.list,system.order.update');
        Route::get('/orders', [\App\Controllers\OrderManagementController::class, 'index'])->middleware('permission:system.order.list');
        Route::get('/export', [\App\Controllers\OrderManagementController::class, 'export'])->middleware('permission:system.order.list,system.order.export');
        Route::put('/orders/{id}/staff', [\App\Controllers\OrderManagementController::class, 'adjust'])->whereNumber('id')->middleware('permission:system.order.list,system.order.update');
        foreach (['overview','currencies','sales-trend','categories','influencers','staff'] as $module) {
            Route::get('/statistics/' . $module, [\App\Controllers\OrderStatisticsController::class, 'show'])
                ->defaults('module', $module)->middleware('permission:system.order.statistics.' . $module);
        }
    });

    // Auth-bound endpoints
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
    });

    // ─── Users API ───────────────────────────────────────
    Route::prefix('users')->group(function () {

        // 读操作：使用权限码而非写死角色列表
        // system.user.list 权限由管理员在 roles 中分配，灵活可配
        Route::middleware('permission:system.user.list')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('users.index');
            Route::get('/all', [UserController::class, 'all'])->name('users.all');
            Route::get('/count', [UserController::class, 'count'])->name('users.count');
            Route::get('/{id}', [UserController::class, 'show'])->name('users.show')
                ->whereNumber('id');
            Route::get('/{id}/permissions', [UserController::class, 'permissions'])->name('users.permissions')
                ->whereNumber('id');
        });

        // Write operations
        Route::post('/{id}/password', [UserController::class, 'changePassword'])->name('users.change_password')
            ->whereNumber('id')->middleware('permission:system.user.update');

        Route::post('/', [UserController::class, 'store'])->name('users.store')
            ->middleware('permission:system.user.create');
        Route::put('/{id}', [UserController::class, 'update'])->name('users.update')
            ->whereNumber('id')
            ->middleware('permission:system.user.update');
        Route::delete('/{id}', [UserController::class, 'destroy'])->name('users.destroy')
            ->whereNumber('id')
            ->middleware('permission:system.user.delete');

        // User ↔ Role assignment
        Route::prefix('{id}')->whereNumber('id')->group(function () {
            Route::get('/role', [UserRoleController::class, 'show'])->name('users.role.show')
                ->middleware('permission:system.user.assign_role');
            Route::put('/role', [UserRoleController::class, 'assign'])->name('users.role.assign')
                ->middleware('permission:system.user.assign_role');
            Route::delete('/role', [UserRoleController::class, 'unassign'])->name('users.role.unassign')
                ->middleware('permission:system.user.assign_role');

            Route::get('/roles', [UserRoleController::class, 'index'])->name('users.roles.index')
                ->middleware('permission:system.user.assign_role');
            Route::put('/roles', [UserRoleController::class, 'sync'])->name('users.roles.sync')
                ->middleware('permission:system.user.assign_role');
            Route::post('/roles', [UserRoleController::class, 'add'])->name('users.roles.add')
                ->middleware('permission:system.user.assign_role');
            Route::delete('/roles/{roleId}', [UserRoleController::class, 'remove'])->name('users.roles.remove')
                ->whereNumber('roleId')
                ->middleware('permission:system.user.assign_role');
        });
    });

    Route::get('/roles/{id}/assignments', [RoleController::class, 'assignments'])->whereNumber('id')->middleware('permission:system.role.list|system.role.assign_permission')->name('roles.assignments');
    Route::get('/roles/all', [RoleController::class, 'all'])->middleware('permission:system.role.list|system.user.assign_role')->name('roles.all');

    // ─── Roles (RBAC) ────────────────────────────────────
    Route::middleware('permission:system.role.list')->prefix('roles')->group(function () {
        Route::get('/', [RoleController::class, 'index'])->name('roles.index');

        Route::get('/{id}', [RoleController::class, 'show'])->name('roles.show')
            ->whereNumber('id');

    });

    Route::middleware('permission:system.role.create')
        ->post('/roles', [RoleController::class, 'store'])->name('roles.store');

    Route::middleware('permission:system.role.update')
        ->put('/roles/{id}', [RoleController::class, 'update'])->name('roles.update')
        ->whereNumber('id');

    Route::middleware('permission:system.role.delete')
        ->delete('/roles/{id}', [RoleController::class, 'destroy'])->name('roles.destroy')
        ->whereNumber('id');

    Route::middleware('permission:system.role.assign_permission')
        ->put('/roles/{id}/permissions', [RoleController::class, 'assignPermissions'])
        ->name('roles.permissions.assign')
        ->whereNumber('id');

    Route::get('/permissions/tree', [PermissionController::class, 'tree'])->middleware('permission:system.permission.read|system.role.assign_permission')->name('permissions.tree');

    // ─── Permissions (unified RBAC) ────────────────────
    // 统一的权限节点管理（菜单节点 + 操作权限点都在同一张 permissions 表）。
    // 主要路由：/api/permissions/*
    // 菜单树不再有独立端点，由 AuthController 在登录 /me 时一并返回完整权限树，
    // 前端按 type=menu 与 type=action 自行拆分。
    Route::middleware('permission:system.permission.read')->prefix('permissions')->group(function () {
        Route::get('/', [PermissionController::class, 'index'])->name('permissions.index');

        Route::get('/{id}', [PermissionController::class, 'show'])->name('permissions.show')
            ->whereNumber('id');
    });

    Route::middleware('permission:system.permission.create')
        ->post('/permissions', [PermissionController::class, 'store'])->name('permissions.store');

    Route::middleware('permission:system.permission.update')
        ->put('/permissions/{id}', [PermissionController::class, 'update'])->name('permissions.update')
        ->whereNumber('id');

    Route::middleware('permission:system.permission.delete')
        ->delete('/permissions/{id}', [PermissionController::class, 'destroy'])->name('permissions.destroy')
        ->whereNumber('id');

    // ─── Orders ─────────────────────────────────────
    // 与 saveb-erp / saveb-source /api/order-search 接口同构的分页筛选接口。
    // 读操作通过 permission:system.order.list 控制；
    // 写操作各自独立权限码，便于精细化授权。
    Route::middleware('permission:system.order.list')->prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/count', [OrderController::class, 'count'])->name('orders.count');
        Route::get('/{id}', [OrderController::class, 'show'])->name('orders.show')
            ->whereNumber('id');
    });

    Route::middleware('permission:system.order.create')
        ->post('/orders', [OrderController::class, 'store'])->name('orders.store');

    Route::middleware('permission:system.order.update')
        ->put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update')
        ->whereNumber('id');

    Route::middleware('permission:system.order.delete')
        ->delete('/orders/{id}', [OrderController::class, 'destroy'])->name('orders.destroy')
        ->whereNumber('id');
});
