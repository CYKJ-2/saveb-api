<?php

namespace App\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 角色拦截中间件。典型用法：Route::middleware('role:admin,viewer')->...
 *
 * 角色匹配规则（参考 ERP auth.ts）：
 *   - 角色名统一小写并 trim
 *   - "cs" 会被规整为 "customer_service"
 *   - 检查用户的所有角色（主角色 role_id + user_roles pivot 中的所有角色），
 *     只要命中任意一个允许的角色即通过
 *
 * 注意：建议优先使用 permission: 中间件进行细粒度权限控制；
 * role: 仅用于粗粒度的"角色白名单"场景。
 */
class RequireRoles
{
    /**
     * 处理请求，校验用户是否拥有任意一个允许的角色。
     *
     * @param  Request  $request  HTTP 请求对象
     * @param  Closure  $next     下一个中间件 / 处理器
     * @param  string   ...$roles 允许的角色 code 列表
     * @return Response           命中则交给下游；否则返回 401/403 错误响应
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->attributes->get('auth_user');

        if (! $user instanceof User) {
            return $this->forbid('authentication_required', 401);
        }

        if (! $user->active) {
            return $this->forbid('authorization_revoked', 401);
        }

        // 超级管理员（任意来源的 super_admin）直接通过
        if ($this->hasRole($user, 'super_admin')) {
            return $next($request);
        }

        $allowed = array_map(
            fn (string $r) => $this->normalize($r),
            $roles,
        );

        // 检查用户的全部角色，只要命中任意一个允许的角色即通过
        foreach ($this->getAllRoleCodes($user) as $code) {
            if (in_array($code, $allowed, true)) {
                return $next($request);
            }
        }

        return $this->forbid('forbidden');
    }

    /**
     * 获取用户在所有来源中的全部角色 code（去重）。
     *
     * 来源：
     *   1. users.role_id → roles.code（主角色，兼容旧数据）
     *   2. user_roles pivot → roles.code（多对多，标准 RBAC）
     *
     * @param  User  $user  当前用户（应已预加载 role 与 roles）
     * @return array<int, string>  角色 code 列表（可能为空）
     */
    private function getAllRoleCodes(User $user): array
    {
        return array_map(fn ($code) => $this->normalize($code), $user->effectiveRoleCodes());
    }

    /**
     * 判断用户是否拥有指定角色。
     *
     * @param  User    $user  当前用户
     * @param  string  $code  角色 code（小写形式传入）
     * @return bool          拥有该角色返回 true
     */
    private function hasRole(User $user, string $code): bool
    {
        foreach ($this->getAllRoleCodes($user) as $userCode) {
            if ($userCode === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * 角色名规整：小写、trim、别名归一。
     *
     * @param  string  $role  原始角色 code
     * @return string         规整后的角色 code
     */
    private function normalize(string $role): string
    {
        $value = strtolower(trim($role));

        return $value === 'cs' ? 'customer_service' : $value;
    }

    /**
     * 构造拒绝响应。
     *
     * @param  string       $code    错误代码标识
     * @param  int          $status  HTTP 状态码
     * @return JsonResponse          错误响应体
     */
    private function forbid(string $code, int $status = 403): JsonResponse
    {
        $msg = match ($code) {
            'authentication_required' => '需要登录。',
            'authorization_revoked'   => '账户已禁用。',
            default                   => '您没有执行该操作的权限。',
        };

        return response()->json([
            'success' => false,
            'error'   => $code,
            'message' => $msg,
        ], $status);
    }
}
