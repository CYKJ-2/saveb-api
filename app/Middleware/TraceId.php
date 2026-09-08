<?php

namespace App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 为每个请求分配唯一 Trace-Id。
 *
 * 解析顺序（首个非空胜出）：
 *   1. X-Trace-Id 请求头（前端自行注入，便于跨服务串联）
 *   2. X-Request-Id 请求头（通用备选）
 *   3. X-Correlation-Id 请求头
 *   4. trace_id 查询参数 / 请求体字段
 *   5. 自动生成 `req_<26 位>`（约 128 位熵）
 *
 * Trace-Id 的用途：
 *   - 写入 $request->attributes('trace_id') 供下游代码使用
 *   - 在响应头 `X-Trace-Id` 中回传，便于前端串联
 *   - 进入每条 access.log 日志
 */
class TraceId
{
    /** Request attribute 名称 */
    public const ATTR        = 'trace_id';

    /** 主请求头 */
    public const HEADER      = 'X-Trace-Id';

    /** 备选请求头 */
    public const ALT_HEADER = 'X-Request-Id';

    /** 关联性追踪请求头 */
    public const CORR_HEADER = 'X-Correlation-Id';

    /** Trace-Id 最大长度 */
    public const MAX_LEN    = 128;

    /**
     * 处理请求：解析并注入 Trace-Id。
     *
     * @param  Request   $request  HTTP 请求对象
     * @param  Closure   $next     下一个中间件 / 处理器
     * @return Response            处理后的响应（带 Trace-Id 头）
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolve($request);

        $request->attributes->set(self::ATTR, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /**
     * 按解析顺序获取 Trace-Id。
     *
     * @param  Request  $request  HTTP 请求对象
     * @return string             最终生效的 Trace-Id
     */
    private function resolve(Request $request): string
    {
        foreach ([
            $request->header(self::HEADER),
            $request->header(self::ALT_HEADER),
            $request->header(self::CORR_HEADER),
            $request->input('trace_id'),
        ] as $candidate) {
            if (is_string($candidate) && $this->isValid($candidate)) {
                // 统一规整为 32 位 hex，便于与 novel-api 的 md5 trace_id 格式保持一致（可在日志中 grep 匹配）
                $hashed = md5($candidate);

                return substr($hashed, 0, self::MAX_LEN);
            }
        }

        // 自动生成：32 位 hex（与 novel-api 保持一致）
        return md5((string) microtime(true) . mt_rand(100000, 999999));
    }

    /**
     * 校验候选 Trace-Id 的合法性。
     *
     * @param  string  $value  候选 Trace-Id
     * @return bool            合法返回 true
     */
    private function isValid(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_LEN) {
            return false;
        }

        // 仅允许 URL-safe 可打印 ASCII（避免日志 / 头 / 查询字符串中出现转义问题）
        return (bool) preg_match('/^[A-Za-z0-9_\-\.:]+$/', $value);
    }
}
