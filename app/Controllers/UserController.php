<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * 用户管理控制器。
 *
 * 端点：
 *   GET    /api/users                       → 用户分页列表
 *   GET    /api/users/all                   → 所有用户（扁平列表，不分页）
 *   GET    /api/users/count                 → 用户总数统计
 *   GET    /api/users/{id}                  → 用户详情
 *   POST   /api/users                       → 创建用户
 *   PUT    /api/users/{id}                  → 更新用户
 *   DELETE /api/users/{id}                  → 删除用户（软删除）
 *   GET    /api/users/{id}/permissions      → 获取用户拥有的所有权限点
 *   POST   /api/users/{id}/password         → 修改用户密码
 */
class UserController extends BaseController
{
    /**
     * 构造函数，注入用户服务层。
     *
     * @param  UserService  $userService  用户业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly UserService $userService)
    {
    }

    /**
     * GET /api/users
     *
     * 查询参数：
     *   - page      int   1-based 页码，默认 1
     *   - per_page  int   每页条数（最大 100），默认 20
     *   - active    bool  仅显示启用账户，默认 false
     *
     * 请求字段（校验规则）：
     * - username：'nullable|string'
     * - display_name：'nullable|string'
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse 分页响应（data 为用户数组，meta 含分页信息）
     * @see UserService::list()
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $activeOnly = filter_var($request->query('active', false), FILTER_VALIDATE_BOOLEAN);
        $filters = $request->validate([
            'username' => 'nullable|string',
            'display_name' => 'nullable|string',
        ]);
        $active = $request->filled('active') ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $result = $this->userService->list($page, $perPage, $active, $filters['username'] ?? null, $filters['display_name'] ?? null);

        return AppResponse::success(
            collect($result->items())->map(fn ($user) => $this->presentUser($user, true)),
            null,
            RespDef::CODE_SUCCESS,
            [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        );
    }

    /**
     * GET /api/users/all
     *
     * 不分页，返回所有用户（一般用于小型数据集 / 导出场景）。
     *
     * 查询参数：
     *   - active  bool  仅显示启用账户，默认 false
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse data 为用户数组，meta.total 为实际数量
     * @see UserService::all()
     */
    public function all(Request $request): JsonResponse
    {
        $activeOnly = filter_var($request->query('active', false), FILTER_VALIDATE_BOOLEAN);
        $users = $this->userService->all($activeOnly);

        return AppResponse::success($users->map(fn ($user) => $this->presentUser($user, true)), null, RespDef::CODE_SUCCESS, ['total' => $users->count()]);
    }

    /**
     * GET /api/users/{id}
     *
     * 获取用户详情（包含其所有角色）。
     * 不存在时返回 404。
     *
     * @param  int  $id  用户主键 ID
     * @return JsonResponse 单个用户对象
     * @see UserService::find()
     */
    public function show(int $id): JsonResponse
    {
        $user = $this->userService->find($id);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => "User #{$id} not found.",
            ], 404);
        }

        return AppResponse::success($this->presentUser($user, true));
    }

    /**
     * GET /api/users/count
     *
     * 统计用户总数，可按启用状态过滤。
     *
     * 查询参数：
     *   - active  bool  仅统计启用账户，默认 false
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse { total: int, active_only: bool }
     * @see UserService::count()
     */
    public function count(Request $request): JsonResponse
    {
        $activeOnly = filter_var($request->query('active', false), FILTER_VALIDATE_BOOLEAN);

        return AppResponse::success([
            'total' => $this->userService->count($activeOnly),
            'active_only' => $activeOnly,
        ]);
    }

    /**
     * POST /api/users
     *
     * 创建新用户。username 必须全局唯一。
     *
     * 请求体字段：
     *   username             string  必填，全局唯一
     *   password             string  必填，最少 6 字符
     *   display_name         string? 显示名称
     *   role_id              int?    主角色（users.role_id）ID
     *   role_ids             int[]?  通过 user_roles 分配的全部角色 ID 列表
     *   staff_code           string? 员工编码
     *   active               bool?   是否启用，默认 1
     *   must_change_password bool?   是否强制首次登录改密，默认 0
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse HTTP 201，新创建的用户（含角色）
     * @see UserService::create()
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:64',
            'password' => 'required|string|min:6',
            'display_name' => 'nullable|string|max:100',
            'role_id' => 'nullable|integer|exists:roles,id',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'integer|exists:roles,id',
            'staff_code' => 'nullable|string|max:64',
            'active' => 'nullable|boolean',
            'must_change_password' => 'nullable|boolean',
        ]);
        $this->guardRoleFields($request, $data);
        $user = \Illuminate\Support\Facades\DB::connection('pgsql')
            ->transaction(fn () => $this->userService->create($data));

        return AppResponse::success($this->presentUser($user, true), null, RespDef::CODE_SUCCESS, [], 201);
    }

    /**
     * PUT /api/users/{id}
     *
     * 更新已有用户。username 若变更则需再次校验唯一性。
     *
     * 请求体字段（与 create 一致，所有字段可选）：
     *   username             string?
     *   password             string?  至少 6 字符
     *   display_name         string?
     *   role_id              int?
     *   role_ids             int[]?  提供则全量替换 user_roles
     *   staff_code           string?
     *   active               bool?
     *   must_change_password bool?
     *
     * @param  int  $id  用户主键 ID
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse 更新后的用户（含角色）
     * @see UserService::update()
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'sometimes|string|max:64',
            'password' => 'sometimes|string|min:6',
            'display_name' => 'nullable|string|max:100',
            'role_id' => 'nullable|integer|exists:roles,id',
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'integer|exists:roles,id',
            'staff_code' => 'nullable|string|max:64',
            'active' => 'nullable|boolean',
            'must_change_password' => 'nullable|boolean',
        ]);
        $this->guardRoleFields($request, $data);
        $user = \Illuminate\Support\Facades\DB::connection('pgsql')
            ->transaction(fn () => $this->userService->update($id, $data));

        return AppResponse::success($this->presentUser($user, true));
    }

    /**
     * DELETE /api/users/{id}
     *
     * 软删除用户（设置 deleted_at）。不允许删除自己登录的账户。
     * 用户不存在时抛 404 + CODE_USER_NOT_FOUND；
     * 尝试删除自己时抛 422（code -1203）。
     *
     * @param  int  $id  用户主键 ID
     * @return JsonResponse {deleted: true}
     * @see UserService::delete()
     */
    public function destroy(int $id): JsonResponse
    {
        $this->userService->delete($id);

        return AppResponse::success(['deleted' => true]);
    }

    /**
     * GET /api/users/{id}/permissions
     *
     * 获取用户在所有角色下拥有的权限点（已去重）。
     * 注意：返回的是扁平权限点数组，不含树形结构。
     *
     * @param  int  $id  用户主键 ID
     * @return JsonResponse 权限点数组
     * @see UserService::getUserPermissions()
     */
    public function permissions(int $id): JsonResponse
    {
        $permissions = $this->userService->getUserPermissions($id);

        return AppResponse::success($permissions);
    }

    /**
     * POST /api/users/{id}/password
     *
     * 修改指定用户的密码。修改后 must_change_password 自动置为 false。
     * 注意：不做旧密码校验，调用方必须是管理员上下文。
     *
     * 请求体：
     *   password  string  必填，新密码，至少 6 字符
     *
     * 请求字段（校验规则）：
     * - password：'required|string|min:6'
     *
     * @param  int  $id  用户主键 ID
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse {message: 'Password changed successfully.'}
     * @see UserService::changePassword()
     */
    public function changePassword(int $id, Request $request): JsonResponse
    {
        $data = $request->validate(['password' => 'required|string|min:6']);
        $this->userService->changePassword($id, $data['password']);

        return AppResponse::success(['message' => 'Password changed successfully.']);
    }

    /**
     * 校验角色分配权限，将兼容主角色合并到 role_ids 后移除 role_id。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 role_ids、role_id；按引用原地更新
     * @return void 原地规范化角色字段；未传角色字段时直接返回
     * @throws \App\Exceptions\SystemException 当前用户没有分配角色权限
     */
    private function guardRoleFields(Request $request, array &$data): void
    {
        if (!array_key_exists('role_id', $data) && !array_key_exists('role_ids', $data)) {
            return;
        }
        $codes = $request->attributes->get('auth_permission_codes', []);
        if (!in_array('*', $codes, true) && !in_array('system.user.assign_role', $codes, true)) {
            throw new \App\Exceptions\SystemException(-1000, '缺少权限：system.user.assign_role', 403);
        }
        // Both legacy and multi-role inputs use the same validated assignment path.
        $ids = $data['role_ids'] ?? [];
        if (!empty($data['role_id'])) {
            $ids[] = $data['role_id'];
        }
        $data['role_ids'] = array_values(array_unique($ids));
        unset($data['role_id']);
    }

    /**
     * 将用户模型转换为列表或详情字段，按需附带角色。
     *
     * @param  mixed  $user  用户模型
     * @param  bool  $includeRoles  是否同时输出角色信息；默认 false
     * @return array 用户公开字段；includeRoles 为 true 时附带角色信息
     */
    private function presentUser($user, bool $includeRoles = false): array
    {
        // 主角色（users.role_id）—— 仅在关系已预加载时取值，
        // 避免对未预加载的关系触发额外的隐式查询
        $primaryRole = $user->relationLoaded('role') && $user->role ? [
            'id' => $user->role->id,
            'code' => $user->role->code,
            'name' => $user->role->name,
            'name_zh' => $user->role->name_zh,
        ] : null;
        $data = [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'role' => $primaryRole,
            'staff_code' => $user->staff_code,
            'active' => (bool) $user->active,
            'must_change_password' => (bool) $user->must_change_password,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
        if ($includeRoles) {
            $data['roles'] = $user->relationLoaded('roles') && $user->roles ? $user->roles
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'code' => $role->code,
                    'name' => $role->name,
                    'name_zh' => $role->name_zh,
                ])
                ->all() : [];
        }

        return $data;
    }
}
