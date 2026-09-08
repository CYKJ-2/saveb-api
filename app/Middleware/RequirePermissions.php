<?php

namespace App\Middleware;

use App\Common\AppResponse;
use App\Common\RespDef;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 权限码拦截中间件。典型用法：
 *   Route::middleware('permission:user.create')->...
 *
 * 解析顺序：
 *   1) 由 Authenticate 中间件注入的 auth_permission_codes 属性
 *   2) '*' 通配符短路通过（super_admin 角色）
 *
 * 拒绝时：返回 403 + 错误码 CODE_PERMISSION_DENIED。
 */
class RequirePermissions
{
    /**
     * 处理请求，校验是否拥有全部必需权限码。
     *
     * @param  Request  $request        HTTP 请求对象
     * @param  Closure  $next           下一个中间件 / 处理器
     * @param  string   ...$requiredCodes 必需权限码列表
     * @return Response                 通过则交给下游；否则返回 401/403 错误响应
     */
    public function handle(Request $request, Closure $next, string ...$requiredCodes): Response
    {
        /** @var User|null $user */
        $user = $request->attributes->get('auth_user');
        if (! $user instanceof User) {
            return AppResponse::error(
                RespDef::CODE_TOKEN_INVALID,
                RespDef::MSG_TOKEN_INVALID,
                401,
            );
        }

        $granted = (array) $request->attributes->get('auth_permission_codes', []);

        // 超级管理员通配
        if (in_array('*', $granted, true)) {
            return $next($request);
        }

        foreach ($requiredCodes as $code) {
            if (! array_intersect(explode('|', $code), $granted)) {
                return AppResponse::error(
                    RespDef::CODE_PERMISSION_DENIED,
                    sprintf('缺少权限：%s', $code),
                    403,
                );
            }
        }

        return $next($request);
    }
}
