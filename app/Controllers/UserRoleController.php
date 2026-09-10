<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Dao\UserDao;
use App\Exceptions\SystemException;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * 用户 ↔ 角色 关联控制器。
 *
 * 端点：
 *   GET    /api/users/{id}/roles              → 列出用户的所有角色（含主角色）
 *   GET    /api/users/{id}/role               → 获取用户的主角色（users.role_id）
 *   PUT    /api/users/{id}/role               → 设置用户的主角色
 *   DELETE /api/users/{id}/role               → 清除用户的主角色（role_id 置 null）
 *   PUT    /api/users/{id}/roles              → 全量替换用户的角色（user_roles）
 *   POST   /api/users/{id}/roles              → 为用户追加一个角色（user_roles）
 *   DELETE /api/users/{id}/roles/{roleId}     → 移除用户的某个角色
 */
class UserRoleController extends BaseController
{
    /**
     * 构造函数，注入用户 DAO。
     *
     * @param  UserDao  $userDao  用户数据访问对象
     * @param  \App\Services\UserRoleService  $userRoleService  用户角色业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private readonly UserDao $userDao,
        private readonly \App\Services\UserRoleService $userRoleService,
    ) {
    }

    /**
     * GET /api/users/{id}/roles
     *
     * 列出指定用户的主角色 + 所有附加角色。
     *
     * @param  int  $id  用户主键 ID
     * @return JsonResponse { user_id, username, primary_role, roles: [...] }
     * @see UserDao::find()
     */
    public function index(int $id): JsonResponse
    {
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $user->load(['role:id,code,name,name_zh', 'roles:id,code,name,name_zh,description,status']);
        $primaryRole = $user->relationLoaded('role') && $user->role ? [
            'id' => $user->role->id,
            'code' => $user->role->code,
            'name' => $user->role->name,
            'name_zh' => $user->role->name_zh,
        ] : null;

        return AppResponse::success([
            'user_id' => $user->id,
            'username' => $user->username,
            'primary_role' => $primaryRole,
            'roles' => $user->roles
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'name_zh' => $role->name_zh,
                    'description' => $role->description,
                    'status' => $role->status,
                ])
                ->all(),
        ]);
    }

    /**
     * GET /api/users/{id}/role
     *
     * 获取用户的"主角色"（users.role_id 字段对应的角色）。
     *
     * @param  int  $id  用户主键 ID
     * @return JsonResponse { user_id, role }
     * @see UserDao::find()
     */
    public function show(int $id): JsonResponse
    {
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $user->load('role:id,code,name,name_zh');
        $primaryRole = $user->relationLoaded('role') && $user->role ? [
            'id' => $user->role->id,
            'code' => $user->role->code,
            'name' => $user->role->name,
            'name_zh' => $user->role->name_zh,
        ] : null;

        return AppResponse::success([
            'user_id' => $user->id,
            'role' => $primaryRole,
        ]);
    }

    /**
     * PUT /api/users/{id}/role
     *
     * 设置用户的主角色（写入 users.role_id）。
     * 入参二选一即可：role_id 或 role_code。
     *
     * 请求体：
     *   role_id    int?    角色主键 ID
     *   role_code  string? 角色 code
     *
     * 请求字段（校验规则）：
     * - role_id：'nullable|integer|exists:roles,id'
     * - role_code：'nullable|string|max:64|exists:roles,code'
     *
     * @param  int  $id  用户主键 ID
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse { user_id, role }
     * @see UserDao::find()
     * @see \App\Services\UserRoleService::sync()
     */
    public function assign(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_id' => 'nullable|integer|exists:roles,id',
            'role_code' => 'nullable|string|max:64|exists:roles,code',
        ]);
        if (empty($data['role_id']) && empty($data['role_code'])) {
            throw new SystemException(RespDef::CODE_INVALID_PARAMS, 'role_id or role_code is required.', 422);
        }
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $role = !empty($data['role_id']) ? Role::find($data['role_id']) : Role::where('code', $data['role_code'])->first();
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }
        $this->userRoleService->sync($user, [$role->id]);

        return AppResponse::success([
            'user_id' => $user->id,
            'role' => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'name_zh' => $role->name_zh,
            ],
        ]);
    }

    /**
     * DELETE /api/users/{id}/role
     *
     * 清除用户的主角色（users.role_id 置为 NULL）。
     *
     * @param  int  $id  用户主键 ID
     * @return JsonResponse { user_id, role: null }
     * @see UserDao::find()
     * @see \App\Services\UserRoleService::sync()
     */
    public function unassign(int $id): JsonResponse
    {
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $ids = $user
            ->roles()
            ->pluck('roles.id')
            ->all();
        $this->userRoleService->sync($user, array_values(array_filter($ids, fn ($roleId) => (int) $roleId !== (int) $user->role_id)));

        return $this->show($id);
    }

    /**
     * PUT /api/users/{id}/roles
     *
     * 全量替换用户的角色集合（写入 user_roles 中间表）。
     * 中间表的 granted_by_user_id 自动填为当前登录用户 ID。
     *
     * 请求体：
     *   role_ids  int[]  必填，角色 ID 列表（替换原列表）
     *
     * 请求字段（校验规则）：
     * - role_ids：'present|array'
     * - role_ids.*：'integer|distinct|exists:roles,id'
     *
     * @param  int  $id  用户主键 ID
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse { user_id, username, roles }
     * @see UserDao::find()
     * @see \App\Services\UserRoleService::sync()
     */
    public function sync(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_ids' => 'present|array',
            'role_ids.*' => 'integer|distinct|exists:roles,id',
        ]);
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $this->userRoleService->sync($user, $data['role_ids']);
        $user->load('roles:id,code,name,name_zh,description,status');

        return AppResponse::success([
            'user_id' => $user->id,
            'username' => $user->username,
            'roles' => $user->roles
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'name_zh' => $role->name_zh,
                    'description' => $role->description,
                    'status' => $role->status,
                ])
                ->all(),
        ]);
    }

    /**
     * POST /api/users/{id}/roles
     *
     * 为用户追加一个角色（不替换原列表）。
     * 若该角色已经分配则直接返回成功，不重复添加。
     *
     * 请求体（二选一）：
     *   role_id    int?
     *   role_code  string?
     *
     * 请求字段（校验规则）：
     * - role_id：'nullable|integer|exists:roles,id'
     * - role_code：'nullable|string|max:64|exists:roles,code'
     *
     * @param  int  $id  用户主键 ID
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse { user_id, username, roles, message? }
     * @see UserDao::find()
     * @see \App\Services\UserRoleService::sync()
     */
    public function add(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'role_id' => 'nullable|integer|exists:roles,id',
            'role_code' => 'nullable|string|max:64|exists:roles,code',
        ]);
        if (empty($data['role_id']) && empty($data['role_code'])) {
            throw new SystemException(RespDef::CODE_INVALID_PARAMS, 'role_id or role_code is required.', 422);
        }
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $role = !empty($data['role_id']) ? Role::find($data['role_id']) : Role::where('code', $data['role_code'])->first();
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }
        // 已分配则短路返回
        if ($user
            ->roles()
            ->where('roles.id', $role->id)
            ->exists()) {
            return AppResponse::success([
                'user_id' => $user->id,
                'role' => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'name_zh' => $role->name_zh,
                ],
                'message' => 'Role already assigned to user.',
            ]);
        }
        $ids = $user
            ->roles()
            ->pluck('roles.id')
            ->all();
        if ($user->role_id) {
            $ids[] = $user->role_id;
        }
        $ids[] = $role->id;
        $this->userRoleService->sync($user, $ids);
        $user->load('roles:id,code,name,name_zh,description,status');

        return AppResponse::success([
            'user_id' => $user->id,
            'username' => $user->username,
            'roles' => $user->roles
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'name_zh' => $role->name_zh,
                    'description' => $role->description,
                    'status' => $role->status,
                ])
                ->all(),
        ]);
    }

    /**
     * DELETE /api/users/{id}/roles/{roleId}
     *
     * 从用户身上移除一个角色。
     *
     * @param  int  $id  用户主键 ID
     * @param  int  $roleId  要移除的角色 ID
     * @return JsonResponse { user_id, username, roles, removed_role }
     * @see UserDao::find()
     * @see \App\Services\UserRoleService::sync()
     */
    public function remove(int $id, int $roleId): JsonResponse
    {
        $user = $this->userDao->find($id);
        if (!$user) {
            throw new SystemException(RespDef::CODE_USER_NOT_FOUND, RespDef::MSG_USER_NOT_FOUND, 404);
        }
        $role = Role::find($roleId);
        if (!$role) {
            throw new SystemException(RespDef::CODE_ROLE_NOT_FOUND, RespDef::MSG_ROLE_NOT_FOUND, 404);
        }
        $ids = $user
            ->roles()
            ->pluck('roles.id')
            ->all();
        if ($user->role_id) {
            $ids[] = $user->role_id;
        }
        $this->userRoleService->sync($user, array_values(array_filter($ids, fn ($id) => (int) $id !== $roleId)));
        $user->load('roles:id,code,name,name_zh,description,status');

        return AppResponse::success([
            'user_id' => $user->id,
            'username' => $user->username,
            'roles' => $user->roles
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'name_zh' => $role->name_zh,
                    'description' => $role->description,
                    'status' => $role->status,
                ])
                ->all(),
            'removed_role' => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'name_zh' => $role->name_zh,
            ],
        ]);
    }
}
