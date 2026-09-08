<?php

namespace App\Middleware;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token 认证中间件。
 *
 * 请求头：Authorization: Bearer <token>
 *
 * 成功后：在 Request 上注入：
 *   - auth_user             = User
 *   - auth_token            = ApiToken
 *   - auth_permission_codes = 用户拥有的权限码扁平数组
 *
 * 失败时：直接返回 401。
 */
class Authenticate
{
    /**
     * 处理请求：解析 token、加载用户与权限。
     *
     * @param  Request   $request  HTTP 请求对象
     * @param  Closure   $next     下一个中间件 / 处理器
     * @return Response            继续向下传递或返回错误响应
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (! $token) {
            return AppResponse::error(
                RespDef::CODE_TOKEN_INVALID,
                RespDef::MSG_TOKEN_INVALID,
                401,
            );
        }

        $row = ApiToken::findByPlain($token);
        if (! $row) {
            return AppResponse::error(
                RespDef::CODE_TOKEN_INVALID,
                RespDef::MSG_TOKEN_INVALID,
                401,
            );
        }

        $user = $row->user;
        if (! $user || ! $user->active) {
            return AppResponse::error(
                RespDef::CODE_ACCOUNT_DISABLED,
                RespDef::MSG_ACCOUNT_DISABLED,
                403,
            );
        }

        // 刷新 last_used_at（非阻塞）
        $row->forceFill(['last_used_at' => now()])->save();

        // 预加载 RBAC 上下文，避免下游 permission:xxx 中间件出现 N+1
        $user->loadFullAuthContext();

        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_token', $row);
        $request->attributes->set('auth_permission_codes', $this->extractPermissionCodes($user));

        if (! $request->isMethod('GET') && $request->is('api/users/*')) {
            $target = \App\Models\User::find($request->route('id'));
            $actorCodes = $request->attributes->get('auth_permission_codes');
            if ($target && ! in_array('*', $actorCodes, true)
                && array_diff(app(\App\Services\RbacService::class)->codes($target), $actorCodes)) {
                return AppResponse::error(RespDef::CODE_PERMISSION_DENIED, '不能修改权限高于自身的账户。', 403);
            }
        }

        return $next($request);
    }

    /**
     * 汇总用户实际拥有的权限码为扁平数组。
     *
     * 超级管理员旁路：任意角色 code=='super_admin' 时直接返回 ['*'] 通配。
     * 来源：user_roles pivot（新 RBAC）+ users.role_id（兼容旧 RBAC）。
     *
     * @param  \App\Models\User  $user  已预加载 role 与 roles.permissions 的用户
     * @return array<int, string>      权限码列表（已去重）
     */
    private function extractPermissionCodes(\App\Models\User $user): array
    {
        return app(\App\Services\RbacService::class)->codes($user);
    }

    /**
     * 从请求中提取 Bearer token。
     *
     * @param  Request    $request  HTTP 请求对象
     * @return string|null          提取到的 token 明文，未找到返回 null
     */
    private function extractToken(Request $request): ?string
    {
        // 1) Authorization: Bearer 头
        $header = $request->header('Authorization', '');
        if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        // 2) 兜底：?api_token=...（浏览器调试用）
        if ($request->filled('api_token')) {
            return (string) $request->query('api_token');
        }

        return null;
    }
}
