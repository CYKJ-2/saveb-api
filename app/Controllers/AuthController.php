<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Exceptions\SystemException;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * 认证控制器。
 *
 * 提供登录、登出、当前用户信息三个端点。
 *
 * 端点：
 *   POST /api/auth/login  → 校验用户名/密码，成功后签发 bearer token
 *   POST /api/auth/logout → 撤销当前请求使用的 bearer token
 *   GET  /api/auth/me     → 返回当前登录用户 + 完整权限树（菜单+action）
 *
 * 设计要点：
 *   - token 是存放在 api_tokens 表的不透明字符串（saveb_ 前缀 + 40 位随机），
 *     仅保存 SHA-256 哈希，原始 token 仅在登录响应中返回一次。
 *   - 原 menus 表已下线，菜单节点与权限点统一在 permissions 表中维护；
 *     完整的权限树在登录与 /me 时统一返回，前端按 type 自行拆分为菜单与权限点。
 */
class AuthController extends BaseController
{
    /**
     * 构造函数，注入认证服务。
     *
     * @param  AuthService  $authService  认证业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly AuthService $authService)
    {
    }

    /**
     * POST /api/auth/login
     *
     * Body: { "username": "...", "password": "...", "token_name": "..." }
     *
     * Success 200:
     *   {
     *     "success": true, "code": 0, "message": "操作成功。", "data": {
     *       token, expires_at, user,
     *       permissions: [{id, parent_id, code, type, children: [...]}]   // menu+action 统一树
     *     }
     *   }
     * Error 401: { "success": false, "code": -1100, "message": "用户名或密码错误。" }
     * Error 403: { "success": false, "code": -1101, "message": "账户已禁用。" }
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse 登录结果
     * @see AuthService::attempt()
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'token_name' => 'nullable|string|max:100',
        ]);
        // AuthService::attempt 会在认证失败时抛 SystemException
        $result = $this->authService->attempt(
            username: $data['username'],
            password: $data['password'],
            ip: $request->ip(),
            userAgent: substr((string) $request->userAgent(), 0, 500),
            tokenName: $data['token_name'] ?? null,
        );
        /** @var User $user */
        $user = $result['user'];
        $token = $result['token'];

        return AppResponse::success(
            [
                'token' => $token['plain'],
                'expires_at' => $token['expires_at']?->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'display_name' => $user->display_name,
                    'role_code' => $user->normalizedRole(),
                    'role_codes' => $user->effectiveRoleCodes(),
                    'staff_code' => $user->staff_code,
                    'must_change_password' => (bool) $user->must_change_password,
                    'roles_i18n' => $user->relationLoaded('roles') ? $user
                        ->effectiveRoles()
                        ->map(fn ($role) => $role->i18n() + ['code' => $role->code])
                        ->all() : [],
                ],
                // 完整的菜单+action 统一树（前端按 type 自行拆分）
                //   - 侧边栏菜单：过滤 type==='menu' && !hidden && status===1
                //   - 权限点：扁平化并过滤 type==='action'
                'permissions' => $this->collectPermissions($user),
            ],
            null,
            RespDef::CODE_SUCCESS,
            ['locale' => $this->resolveLocale($request)],
        );
    }

    /**
     * 解析当前请求应当使用的语言环境（locale）。
     *
     * 优先级：
     *   1) 显式查询参数 ?locale=zh-CN
     *   2) Accept-Language 请求头
     *   3) 默认 'en-us'
     *
     * @param  Request  $request  HTTP 请求对象
     * @return string 规范化后的小写 locale
     */
    private function resolveLocale(Request $request): string
    {
        $explicit = $request->query('locale');
        if (is_string($explicit) && $explicit !== '') {
            return strtolower(trim($explicit));
        }
        $acceptLanguage = $request->header('Accept-Language');
        if (is_string($acceptLanguage) && preg_match('/^([a-zA-Z-]+)/', $acceptLanguage, $matches)) {
            return strtolower(trim($matches[1]));
        }

        return 'en-us';
    }

    /**
     * POST /api/auth/logout
     *
     * 撤销本次请求使用的 bearer token（从 Authorization 头提取）。
     * 如果头中不存在有效 token 返回 401；找不到 token 行时按已登入处理。
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse 成功响应 { message: 'Logged out.' } 或 401
     * @see AuthService::logout()
     */
    public function logout(Request $request): JsonResponse
    {
        $plain = $this->extractPlainToken($request);
        if (!$plain) {
            return AppResponse::error(RespDef::CODE_TOKEN_INVALID, RespDef::MSG_TOKEN_INVALID, 401);
        }
        $this->authService->logout($plain);

        return AppResponse::success(['message' => 'Logged out.']);
    }

    /**
     * GET /api/auth/me
     *
     * 返回当前登录用户及其完整权限树。
     * auth_user / auth_token 属性由 Authenticate 中间件在进入本方法前注入。
     *
     * Success 200:
     *   {
     *     "success": true, "data": {
     *       user: {...},
     *       token: {...},
     *       permissions: [{id, parent_id, code, type, children: [...]}]   // menu+action 统一树
     *     }
     *   }
     *
     * @param  Request  $request  HTTP 请求对象
     * @return JsonResponse 当前登录用户信息
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->attributes->get('auth_user');
        /** @var ApiToken|null $token */
        $token = $request->attributes->get('auth_token');
        if (!$user || !$token) {
            return AppResponse::error(RespDef::CODE_TOKEN_INVALID, RespDef::MSG_TOKEN_INVALID, 401);
        }
        // 确保 role + roles.permissions 已预加载
        $user->loadFullAuthContext();

        return AppResponse::success(
            [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'display_name' => $user->display_name,
                    'role_code' => $user->normalizedRole(),
                    'role_codes' => $user->effectiveRoleCodes(),
                    'staff_code' => $user->staff_code,
                    'must_change_password' => (bool) $user->must_change_password,
                    'roles_i18n' => $user
                        ->effectiveRoles()
                        ->map(fn ($role) => $role->i18n() + ['code' => $role->code])
                        ->all(),
                ],
                'token' => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ],
                'permissions' => $this->collectPermissions($user),
            ],
            null,
            RespDef::CODE_SUCCESS,
            ['locale' => $this->resolveLocale($request)],
        );
    }

    /**
     * 从 Authorization 请求头中提取 Bearer token 明文。
     * 大小写不敏感；提取不到返回 null。
     *
     * @param  Request  $request  HTTP 请求对象
     * @return string|null token 明文，未找到返回 null
     */
    private function extractPlainToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /* ─── Permission & Menu helpers ──────────────────────── */
    /**
     * 汇总当前用户在所有角色下拥有的权限节点（按 code 去重），
     * 返回为一棵带 children 的层级树。
     *
     * 每个节点同时输出扁平的 name/name_zh 与结构化的 i18n 数据，
     * 前端可据此同时构建：
     *   - 侧边栏菜单（过滤 type==='menu' && !hidden && status===1）
     *   - 权限点  （过滤 type==='action'，使用 code/action）
     *
     * 形状示例：
     *   root (level=1, type=menu)
     *   └── page (level=2, type=menu)
     *       └── action (level=3, type=action)
     *
     * @param  User  $user  当前登录用户（已预加载 roles.permissions）
     * @return array<int, array{ id:int, parent_id:int, code:string, name:string, name_zh:?string, i18n: array{name: array{en:string, zh:?string}, description: array{en:?string, zh:?string}}, type:string, action:?string, path:?string, icon:?string, component:?string, level:int, sort:int, status:int, hidden:bool, is_menu_visible:bool, children:array<int, array{...}> }> 当前用户有效权限树，节点包含菜单、操作权限和双语名称
     */
    private function collectPermissions(User $user): array
    {
        $allFlat = [];
        foreach (app(\App\Services\RbacService::class)->nodes($user) as $permission) {
            $allFlat[$permission->id] = $this->presentPermissionNode($permission);
        }
        if (empty($allFlat)) {
            return [];
        }
        // 2) 把子节点挂到父节点（依赖 parent_id < id 的种子顺序）
        $roots = [];
        foreach ($allFlat as $id => $item) {
            $parentId = $item['parent_id'];
            if ($parentId === 0 || !isset($allFlat[$parentId])) {
                $roots[] = & $allFlat[$id];
            } else {
                $allFlat[$parentId]['children'][] = & $allFlat[$id];
            }
        }
        // 3) 每层按 sort 升序排列
        $sortChildren = function (array &$items) use (&$sortChildren): void {
            usort($items, fn ($firstRow, $secondRow) => ($firstRow['sort'] ?? 0) <=> ($secondRow['sort'] ?? 0));
            foreach ($items as &$item) {
                if (!empty($item['children'])) {
                    $sortChildren($item['children']);
                }
            }
        };
        $sortChildren($roots);

        return $roots;
    }

    /**
     * 将单个 Permission 模型渲染为 API 输出格式（带 i18n）。
     *
     * @param  \App\Models\Permission  $permission  权限节点模型
     * @return array 节点字典（含 children 占位）
     */
    private function presentPermissionNode(\App\Models\Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'parent_id' => $permission->parent_id,
            'code' => $permission->code,
            'name' => $permission->name,
            'name_zh' => $permission->name_zh,
            'i18n' => [
                'name' => [
                    'en' => $permission->name,
                    'zh' => $permission->name_zh,
                ],
                'description' => [
                    'en' => $permission->description,
                    'zh' => $permission->description_zh,
                ],
            ],
            'type' => $permission->type,
            // 'menu' | 'action'
            'action' => $permission->action,
            // list | create | update | delete | export | custom
            'path' => $permission->path,
            'icon' => $permission->icon,
            'component' => $permission->component,
            'level' => $permission->level,
            'sort' => $permission->sort,
            'status' => $permission->status,
            'hidden' => (bool) $permission->hidden,
            'is_menu_visible' => (bool) $permission->is_menu_visible,
            'children' => [],
        ];
    }
}
