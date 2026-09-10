<?php

namespace App\Services;

use App\Common\Constants;
use App\Common\RespDef;
use App\Dao\ApiTokenDao;
use App\Dao\UserDao;
use App\Exceptions\SystemException;
use App\Models\ApiToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 认证业务服务。
 *
 * 架构：AuthController → AuthService → [UserDao | ApiTokenDao] → Model
 *
 * 业务规则：
 *   - 登录失败（用户不存在或密码错误）统一返回相同的错误码以防用户名枚举
 *   - 即使用户不存在，也对 dummy 哈希跑一遍 password_verify，保持时间常数一致
 *   - 审计日志（audit_logs 表）属于跨实体操作，保留在 Service 层写入
 */
class AuthService
{
    /**
     * 默认 token 有效期（秒）。可被 Constants::TOKEN_TTL 覆盖。
     */
    public const DEFAULT_TOKEN_TTL = Constants::TOKEN_TTL ?? 86400 * 7;

    /**
     * 构造函数，注入用户与 token 两个 DAO。
     *
     * @param  UserDao  $userDao  用户数据访问对象
     * @param  ApiTokenDao  $apiTokenDao  API token 数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private readonly UserDao $userDao, private readonly ApiTokenDao $apiTokenDao)
    {
    }

    /**
     * 用用户名 + 密码尝试登录，成功后签发新的 API token。
     *
     * @param  string  $username  登录用户名
     * @param  string  $password  登录密码（明文）
     * @param  string|null  $ip  客户端 IP（写入审计日志与 token 行）
     * @param  string|null  $userAgent  客户端 UA（写入 token 行）
     * @param  string|null  $tokenName  token 自定义标签；为空时使用 UA 截断或 'api'
     * @return array{ user: User, token: array{id: int, plain: string, token_hash: string, expires_at: Carbon} } 身份认证结果数组；返回字段：user、token
     * @throws SystemException  凭据无效或账户被禁用
     * @see UserDao::findByUsername()
     * @see ApiTokenDao::create()
     */
    public function attempt(
        string $username,
        string $password,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $tokenName = null,
    ): array {
        if (trim($username) === '' || $password === '') {
            throw new SystemException(RespDef::CODE_INVALID_CREDENTIALS, RespDef::MSG_INVALID_CREDENTIALS, 401);
        }
        $user = $this->userDao->findByUsername($username);
        // 即使用户不存在也跑一次 password_verify，保持时间常数一致
        $dummyHash = '$2b$12$0000000000000000000000000000000000000000000000000000';
        $valid = $user ? password_verify($password, $user->password_hash) : password_verify($password, $dummyHash);
        if (!$valid) {
            throw new SystemException(RespDef::CODE_INVALID_CREDENTIALS, RespDef::MSG_INVALID_CREDENTIALS, 401);
        }
        if (!$user->active) {
            throw new SystemException(RespDef::CODE_ACCOUNT_DISABLED, RespDef::MSG_ACCOUNT_DISABLED, 403);
        }
        // 签发 token
        $plain = 'saveb_' . \Illuminate\Support\Str::random(40);
        $hash = hash('sha256', $plain);
        $row = $this->apiTokenDao->create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'name' => $this->resolveTokenName($tokenName, $userAgent),
            'abilities' => ['*'],
            'ip' => $ip,
            'user_agent' => $userAgent,
            'expires_at' => Carbon::now()->addSeconds(self::DEFAULT_TOKEN_TTL),
        ]);
        // 写审计日志
        $this->writeAudit('login', 'session', (string) $row->id, $user->id, $ip);
        // 预加载角色与权限，便于响应端直接使用
        $user->loadFullAuthContext();

        return [
            'user' => $user,
            'token' => [
                'id' => $row->id,
                'plain' => $plain,
                'token_hash' => $hash,
                'expires_at' => $row->expires_at,
            ],
        ];
    }

    /**
     * 校验明文 token 的有效性，并刷新最近使用时间。
     *
     * @param  string  $plain  Bearer token 明文
     * @return ApiToken 有效 token 行（含 user 关系预加载）
     * @throws SystemException  token 不存在或已过期
     * @see ApiTokenDao::findValidByPlain()
     * @see ApiTokenDao::touch()
     */
    public function validateToken(string $plain): ApiToken
    {
        $token = $this->apiTokenDao->findValidByPlain($plain);
        if (!$token) {
            throw new SystemException(RespDef::CODE_TOKEN_INVALID, RespDef::MSG_TOKEN_INVALID, 401);
        }
        // 刷新最近使用时间（非阻塞）
        $this->apiTokenDao->touch($token->id);

        return $token;
    }

    /**
     * 撤销 token（登出）。过期的 token 视作已登出，按成功处理。
     *
     * @param  string  $plain  Bearer token 明文
     * @return bool true 表示处理成功（无论原 token 是否存在）
     * @see ApiTokenDao::findValidByPlain()
     * @see ApiTokenDao::deleteWhere()
     */
    public function logout(string $plain): bool
    {
        $token = $this->apiTokenDao->findValidByPlain($plain);
        if (!$token) {
            // 过期/无效 token 视作已登出
            return true;
        }
        $this->apiTokenDao->deleteWhere(['id' => $token->id]);
        $this->writeAudit('logout', 'session', (string) $token->id, $token->user_id, $token->ip);

        return true;
    }

    /* ── Private ───────────────────────────────────────── */
    /**
     * 解析 token 的展示标签 `name` 列。
     *
     * 优先级：
     *   1) 调用方显式传入的 tokenName
     *   2) 从 UA 字符串截取前 100 字符
     *   3) fallback 'api'
     *
     * 始终截断到 100 字符（PostgreSQL varchar(100) 上限），
     * 避免出现 "value too long for type character varying(100)" 错误。
     *
     * @param  string|null  $tokenName  显式标签
     * @param  string|null  $userAgent  UA 字符串
     * @return string 处理后的标签（≤100 字符）
     */
    private function resolveTokenName(?string $tokenName, ?string $userAgent): string
    {
        $name = '';
        if ($tokenName !== null && trim($tokenName) !== '') {
            $name = trim($tokenName);
        } elseif ($userAgent) {
            // UA 整体仍存 user_agent 列；这里只截前 100 字符作为 name
            $name = mb_substr(trim($userAgent), 0, 100);
        } else {
            $name = 'api';
        }

        // 多字节安全的硬截断
        return mb_strimwidth($name, 0, 100, '');
    }

    /**
     * 写入审计日志。写入失败仅记录 warning，不影响主业务。
     *
     * @param  string  $action  操作类型，例如 'login' | 'logout'
     * @param  string  $entityType  实体类型，例如 'session'
     * @param  string  $entityId  实体 ID
     * @param  int  $userId  操作人用户 ID
     * @param  string|null  $ip  客户端 IP
     * @return void 无返回值；副作用见方法说明
     */
    private function writeAudit(
        string $action,
        string $entityType,
        string $entityId,
        int $userId,
        ?string $ip,
    ): void {
        try {
            app(\App\Dao\AuditLogDao::class)->create([
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip' => $ip,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to write audit log', [
                'user_id' => $userId,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
