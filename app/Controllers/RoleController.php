<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * 角色管理控制器。
 *
 * 端点：
 *   GET    /api/roles                       → 角色分页列表
 *   GET    /api/roles/all                   → 所有启用的角色（扁平列表）
 *   GET    /api/roles/{id}                  → 角色详情
 *   POST   /api/roles                       → 创建角色
 *   PUT    /api/roles/{id}                  → 更新角色
 *   DELETE /api/roles/{id}                  → 删除角色（非内置且无活跃用户）
 *   GET    /api/roles/{id}/assignments      → 获取角色已分配的权限节点 ID 列表
 *   PUT    /api/roles/{id}/permissions      → 全量替换角色的权限分配
 */
class RoleController extends BaseController
{
    /**
     * 构造函数，注入角色服务层。
     *
     * @param  RoleService  $roleService  角色业务服务
     */
    public function __construct(private readonly RoleService $roleService)
    {
    }

    /**
     * GET /api/roles
     *
     * 查询参数：
     *   - page      int     1-based 页码，默认 1
     *   - per_page  int     每页条数（最大 100），默认 20
     *   - keyword   string  模糊匹配 code/name/name_zh
     *   - status    int     状态过滤：1=启用，0=禁用
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse       包含 list/total/page/per_page 的分页响应
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $keyword = $request->query('keyword');
        $status = $request->query('status');
        $result = $this->roleService->list($page, $perPage, is_string($keyword) ? $keyword : null, $status !== null ? (int) $status : null);

        return AppResponse::success([
            'list' => collect($result->items())
                ->map(fn ($role) => $this->presentRole($role))
                ->all(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'per_page' => $result->perPage(),
        ]);
    }

    /**
     * GET /api/roles/all
     *
     * 获取所有启用状态的角色（status=1），扁平列表不分页。
     * 典型用途：前端"分配角色"下拉框 / 选择器数据源。
     *
     * @return JsonResponse  启用角色的扁平数组
     */
    public function all(): JsonResponse
    {
        return AppResponse::success(collect($this->roleService->all())
            ->map(fn ($role) => $this->presentRole($role))
            ->all());
    }

    /**
     * GET /api/roles/{id}
     *
     * 获取角色详情，附带其所有权限节点。
     * 不存在时抛 404 + CODE_ROLE_NOT_FOUND。
     *
     * @param  int          $id  角色主键 ID
     * @return JsonResponse      单个角色对象
     */
    public function show(int $id): JsonResponse
    {
        $role = $this->roleService->find($id);

        return AppResponse::success($this->presentRole($role));
    }

    /**
     * POST /api/roles
     *
     * 创建一个新的角色。code 必须全局唯一。
     *
     * 请求体字段：
     *   code           string  必填，全局唯一，例如 "ops_manager"
     *   name           string  必填，显示名称（英文/默认）
     *   name_zh        string? 中文显示名称
     *   description    string? 英文描述
     *   description_zh string? 中文描述
     *   status         int?    1=启用，0=禁用，默认 1
     *   sort           int?    排序权重，默认 100
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse       HTTP 201，新创建的角色
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:64',
            'name' => 'required|string|max:100',
            'name_zh' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'description_zh' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
            'sort' => 'nullable|integer',
        ]);
        $role = $this->roleService->create($data);

        return AppResponse::success($this->presentRole($role), null, RespDef::CODE_SUCCESS, [], 201);
    }

    /**
     * PUT /api/roles/{id}
     *
     * 更新一个已有角色。内置角色（is_system=true）不允许修改 code。
     * 未提供的字段保持原值；显式传 null 的可空字段会被置空。
     *
     * @param  int      $id      角色主键 ID
     * @param  Request  $request HTTP 请求对象
     * @return JsonResponse      更新后的角色
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'sometimes|string|max:64',
            'name' => 'sometimes|string|max:100',
            'name_zh' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:255',
            'description_zh' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
            'sort' => 'nullable|integer',
        ]);
        $role = $this->roleService->update($id, $data);

        return AppResponse::success($this->presentRole($role));
    }

    /**
     * DELETE /api/roles/{id}
     *
     * 删除一个角色。要求：
     *   - 不是内置角色（is_system=false）
     *   - 当前没有活跃用户挂载（users.role_id 与 user_roles 都没有）
     *
     * 失败时分别抛 422 + CODE_SYSTEM_ROLE_PROTECTED / CODE_ROLE_HAS_USERS。
     *
     * @param  int          $id  角色主键 ID
     * @return JsonResponse      {deleted: true}
     */
    public function destroy(int $id): JsonResponse
    {
        $this->roleService->delete($id);

        return AppResponse::success(['deleted' => true]);
    }

    /**
     * GET /api/roles/{id}/assignments
     *
     * 获取某角色已分配的权限节点 ID 列表。
     *
     * @param  int          $id  角色主键 ID
     * @return JsonResponse      { permissions: [int, int, ...] }
     */
    public function assignments(int $id): JsonResponse
    {
        return AppResponse::success($this->roleService->getAssignments($id));
    }

    /**
     * PUT /api/roles/{id}/permissions
     *
     * 全量替换某角色的权限分配。可接受两种入参形式（任选其一即可）：
     *   - permission_ids: 权限节点 ID 数组（int）
     *   - permissions:    权限节点 code 数组（string）
     *
     * 入参为数字 ID 视为 ID；为字符串视为 code，service 层会查表转换。
     *
     * @param  int      $id      角色主键 ID
     * @param  Request  $request HTTP 请求对象
     * @return JsonResponse      更新后的角色
     */
    public function assignPermissions(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'permission_ids' => 'sometimes|array',
            'permissions' => 'sometimes|array',
            'permission_ids.*' => 'integer|distinct|exists:permissions,id,deleted_at,NULL',
            'permissions.*' => 'string|distinct|exists:permissions,code,deleted_at,NULL',
        ]);
        if (!array_key_exists('permission_ids', $data) && !array_key_exists('permissions', $data)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['permission_ids' => '必须提供权限列表，可传空数组清空。']);
        }
        $payload = $data['permission_ids'] ?? $data['permissions'] ?? [];
        $role = $this->roleService->assignPermissions($id, $payload);

        return AppResponse::success($this->presentRole($role));
    }

    /* ─── Serialiser ──────────────────────────────────── */
    /**
     * 将 Role 模型序列化为 API 输出格式。
     *
     * @param  \App\Models\Role  $role  角色模型
     * @return array                    输出数组
     */
    private function presentRole($role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'name_zh' => $role->name_zh,
            'description' => $role->description,
            'description_zh' => $role->description_zh,
            'status' => $role->status,
            'is_system' => (bool) $role->is_system,
            'sort' => $role->sort,
            // 该角色下全部权限节点（菜单 + action 都包含）
            'permissions' => $role->relationLoaded('permissions') ? $role->permissions
                ->map(fn ($permission) => [
                    'id' => $permission->id,
                    'code' => $permission->code,
                    'name' => $permission->name,
                    'name_zh' => $permission->name_zh,
                    'type' => $permission->type,
                    'action' => $permission->action,
                ])
                ->all() : [],
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }
}
