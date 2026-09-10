<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Models\Permission;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * 权限节点管理控制器。
 *
 * 统一管理"菜单节点"与"操作权限点"——两者都存在同一张 permissions 表中，
 * 仅靠 type 字段区分（type=menu | type=action）。
 *
 * 端点：
 *   GET    /permissions           → 列表（支持 ?type=menu|action、?parent_id）
 *   GET    /permissions/{id}      → 单个节点
 *   POST   /permissions           → 新增节点（菜单或权限点）
 *   PUT    /permissions/{id}      → 更新节点
 *   DELETE /permissions/{id}      → 软删除节点及全部后代，有效权限计算排除已删除节点
 *
 * 注意：原 /permissions/tree（菜单树）端点已下线；
 * 完整的菜单+权限树在登录或 /auth/me 时由 AuthController 一并返回，
 * 前端从统一树中按 type=menu 与 type=action 自行拆分使用。
 */
class PermissionController extends BaseController
{
    /**
     * 构造函数，注入权限服务层。
     *
     * @param  PermissionService  $permissionService  权限业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly PermissionService $permissionService)
    {
    }

    /* ─── List ──────────────────────────────────────────── */
    /**
     * GET /permissions
     *
     * 支持的可选查询参数：
     *   - type      string  按节点类型过滤：menu | action；不传则返回全部
     *   - parent_id int     按父节点 ID 过滤；不传则返回全部
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse 节点数组
     * @see PermissionService::all()
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $parentId = $request->query('parent_id');
        $list = $this->permissionService->all();
        if ($parentId !== null) {
            $list = $list->where('parent_id', (int) $parentId);
        }
        if ($type === Permission::TYPE_ACTION) {
            $list = $list->where('type', Permission::TYPE_ACTION);
        }
        // 显式 type=menu 时再做一次内存过滤
        if ($type === Permission::TYPE_MENU) {
            $list = $list->where('type', Permission::TYPE_MENU);
        }

        return AppResponse::success($list
            ->map(fn ($permission) => $this->presentNode($permission, false))
            ->values()
            ->all());
    }

    /**
     * GET /permissions/tree
     *
     * 返回整棵权限节点森林。
     *
     * 数据结构：
     *   - 顶层是根节点集合（parent_id=0）
     *   - 每个节点带 children 数组，递归至叶子
     *   - 单个节点的字段与 GET /permissions/{id} 一致
     *
     * 用法：
     *   - 前端角色分配菜单树
     *   - 任意"树形展示"场景（菜单管理、权限选择器等）
     *
     * 注：本端点与 /permissions（扁平列表）共存，由调用方按需选择。
     *    扁平列表更适合做表格 / 列表场景；tree 端点更适合做级联选择器 / 树形展示。
     *
     * @return JsonResponse 节点数组（每个节点带 children 字段）
     * @see PermissionService::tree()
     */
    public function tree(): JsonResponse
    {
        $tree = $this->permissionService->tree();

        return AppResponse::success($tree
            ->map(fn ($permission) => $this->presentNode($permission, true))
            ->values()
            ->all());
    }

    /* ─── CRUD ─────────────────────────────────────────── */
    /**
     * GET /permissions/{id}
     *
     * 获取单个权限节点详情。
     * 节点不存在时返回 404 + CODE_PERMISSION_NOT_FOUND。
     *
     * @param  int  $id  权限节点主键 ID
     * @return JsonResponse 单个节点
     * @see PermissionService::find()
     */
    public function show(int $id): JsonResponse
    {
        $permission = $this->permissionService->find($id);

        return AppResponse::success($this->presentNode($permission, false));
    }

    /**
     * POST /permissions
     *
     * 创建新的权限节点（菜单或 action）。
     * code 必须全局唯一；parent_id 必须存在或为 0（顶级）。
     * level 字段若未指定则按 parent.level + 1 自动推导，最深 3 级。
     *
     * 请求体字段：
     *   code           string   必填，全局唯一，例如 "system.user.create"
     *   name           string   必填，显示名称（英文/默认）
     *   name_zh        string?  中文显示名称（前端中英切换）
     *   type           string?  "menu" | "action"，默认 "menu"
     *   parent_id      int?     父节点 ID，0 表示顶级
     *   path           string?  前端路由路径（仅 menu 有效）
     *   icon           string?  图标名称（仅 menu 有效）
     *   component      string?  前端组件路径（仅 menu 有效）
     *   action         string?  操作类型：list|create|update|delete|export|custom（仅 action 有效）
     *   resource       string?  资源标识（用于资源级权限控制）
     *   level          int?     1|2|3，未传则自动推导
     *   sort           int?     同级排序权重
     *   status         int?     1=启用，0=禁用
     *   hidden         bool?    是否隐藏菜单
     *   description    string?  英文描述
     *   description_zh string?  中文描述
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse HTTP 201，新创建的节点
     * @see PermissionService::create()
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:100',
            'name' => 'required|string|max:100',
            'name_zh' => 'nullable|string|max:100',
            'type' => 'nullable|string|in:menu,action',
            'parent_id' => 'nullable|integer|min:0',
            'path' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:64',
            'component' => 'nullable|string|max:255',
            'action' => 'nullable|string|max:32',
            'resource' => 'nullable|string|max:100',
            'level' => 'nullable|integer|in:1,2,3',
            'sort' => 'nullable|integer',
            'status' => 'nullable|integer|in:0,1',
            'hidden' => 'nullable|boolean',
            'description' => 'nullable|string|max:255',
            'description_zh' => 'nullable|string|max:255',
        ]);
        $permission = $this->permissionService->create($data);

        return AppResponse::success($this->presentNode($permission, false), null, RespDef::CODE_SUCCESS, [], 201);
    }

    /**
     * PUT /permissions/{id}
     *
     * 更新一个已有的权限节点。code 字段不允许修改（建议删除重建）。
     * 字段规则与 create 一致：未传字段保留原值；传 null 表示显式置空。
     *
     * 请求字段（校验规则）：
     * - code：'sometimes|string|max:100'
     * - name：'sometimes|string|max:100'
     * - name_zh：'nullable|string|max:100'
     * - path：'nullable|string|max:255'
     * - icon：'nullable|string|max:64'
     * - component：'nullable|string|max:255'
     * - action：'nullable|string|max:32'
     * - resource：'nullable|string|max:100'
     * - parent_id：'nullable|integer|min:0'
     * - level：'nullable|integer|in:1,2,3'
     * - sort：'nullable|integer'
     * - status：'nullable|integer|in:0,1'
     * - hidden：'nullable|boolean'
     * - description：'nullable|string|max:255'
     * - description_zh：'nullable|string|max:255'
     *
     * @param  int  $id  权限节点主键 ID
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse 更新后的节点
     * @see PermissionService::update()
     */
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'sometimes|string|max:100',
            'name' => 'sometimes|string|max:100',
            'name_zh' => 'nullable|string|max:100',
            'path' => 'nullable|string|max:255',
            'icon' => 'nullable|string|max:64',
            'component' => 'nullable|string|max:255',
            'action' => 'nullable|string|max:32',
            'resource' => 'nullable|string|max:100',
            'parent_id' => 'nullable|integer|min:0',
            'level' => 'nullable|integer|in:1,2,3',
            'sort' => 'nullable|integer',
            'status' => 'nullable|integer|in:0,1',
            'hidden' => 'nullable|boolean',
            'description' => 'nullable|string|max:255',
            'description_zh' => 'nullable|string|max:255',
        ]);
        $permission = $this->permissionService->update($id, $data);

        return AppResponse::success($this->presentNode($permission, false));
    }

    /**
     * DELETE /permissions/{id}
     *
     * 软删除指定节点及其全部后代。
     * 角色授权关联保留，由有效权限计算排除已删除节点。
     *
     * @param  int  $id  权限节点主键 ID
     * @return JsonResponse {deleted: true}
     * @see PermissionService::delete()
     */
    public function destroy(int $id): JsonResponse
    {
        $this->permissionService->delete($id);

        return AppResponse::success(['deleted' => true]);
    }

    /* ─── Serialiser ──────────────────────────────────── */
    /**
     * 将 Permission 模型序列化为 API 输出格式。
     *
     * @param  Permission  $node  权限节点模型
     * @param  bool  $includeChildren  是否递归填充 children（子树）
     * @return array 输出数组
     */
    private function presentNode(Permission $node, bool $includeChildren): array
    {
        $out = [
            'id' => $node->id,
            'parent_id' => $node->parent_id,
            'code' => $node->code,
            'name' => $node->name,
            'name_zh' => $node->name_zh,
            'type' => $node->type,
            'path' => $node->path,
            'icon' => $node->icon,
            'component' => $node->component,
            'action' => $node->action,
            'resource' => $node->resource,
            'level' => $node->level,
            'is_menu_visible' => (bool) $node->is_menu_visible,
            'hidden' => (bool) $node->hidden,
            'sort' => $node->sort,
            'status' => $node->status,
            'description' => $node->description,
            'description_zh' => $node->description_zh,
            'created_at' => $node->created_at,
            'updated_at' => $node->updated_at,
        ];
        if ($includeChildren && $node->relationLoaded('children')) {
            $out['children'] = $node->children
                ->map(fn ($childPermission) => $this->presentNode($childPermission, true))
                ->values()
                ->all();
        } else {
            $out['children'] = [];
        }

        return $out;
    }
}
