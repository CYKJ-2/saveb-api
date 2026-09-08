<?php

namespace App\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 为每个 HTTP 请求写入一行访问日志 `storage/logs/access-YYYY-MM-DD.log`。
 *
 * 最终输出格式（由 {@see \App\Util\LogFormatter} 与本中间件共同决定）：
 *
 *   [YYYY-MM-DD HH:MM:SS][trace_id][LEVEL][ip] URL: '/path', STATUS_CODE: '200',
 *     METHOD: 'GET', BODY_PARAMS: '...', RAW_BODY: '...',
 *     HEADERS: '{"host":"..."}'; RESPONSE: '{"code":0,"message":"ok","data":null}'
 *
 * 该格式与 novel-api/app/middleware/RequestLogMiddleware.php 完全一致——
 * 字段顺序（URL → STATUS_CODE → METHOD → BODY_PARAMS → RAW_BODY →
 * HEADERS → RESPONSE）保持不变，日志解析脚本可在两套系统间复用。
 *
 * 跨文件关联
 * ─────────
 * {@see \App\Util\LogFormatter} 会把同一个 32 位 md5 trace_id 写到所有通道
 * （sql / sql_error / access / error / laravel）的记录前缀。
 * 因此 `grep <trace_id> sql.log access.log` 能完整还原一次请求：
 * 触发该请求的全部 SQL + HTTP 请求本身。
 *
 * 本中间件注册为全局中间件，确保 401/403/500 路径也能被记录。
 */
class AccessLog
{
    /**
     * 对这些路径跳过 body / response 记录（大体量或二进制载荷）。
     *
     * @var array<int, string>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => &$value) {
            if (preg_match('/password|token|authorization|cookie/i', (string) $key)) {
                $value = '[REDACTED]';
            } elseif (is_array($value)) {
                $value = $this->redact($value);
            }
        }

        return $data;
    }

    private const SKIP_BODY_PATHS = [
        '/common/upload',
        '/api/upload',
        '/api/import',
    ];

    /**
     * 处理请求并写入访问日志。
     *
     * @param  Request   $request  HTTP 请求对象
     * @param  Closure   $next     下一个中间件 / 处理器
     * @return Response            处理后的响应
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->write($request, 500, $start, null, $e);
            throw $e;
        }

        $this->write($request, $response->getStatusCode(), $start, $response);

        return $response;
    }

    /**
     * 写入一行访问日志。
     *
     * @param  Request        $request   HTTP 请求对象
     * @param  int            $status    HTTP 状态码
     * @param  float          $start     请求开始时间戳（microtime）
     * @param  Response|null  $response  响应对象（异常路径下可为 null）
     * @param  Throwable|null $error     异常对象（成功路径下为 null）
     */
    private function write(Request $request, int $status, float $start, ?Response $response, ?Throwable $error = null): void
    {
        // 命中大体量 / 二进制载荷路径 → body / response 记为 "skip-record"
        // 占位符，保证列宽一致以便日志解析
        $path     = '/' . ltrim($request->path(), '/');
        $skipBody = $this->shouldSkipBody($path);
        $rawBody  = $skipBody
            ? 'skip-record'
            : trim(preg_replace("/(\r\n|\n|\r|\t)/i", ' ', (string) $request->getContent()));

        // query 与 body 的合并（form-encoded）—— `$request->all()` 合并
        // query、request 与路由参数，最贴近 novel-api 语义。
        // headers 输出采用 JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE，
        // 与 novel-api 输出一致（`"host":"localhost:8080"` 而非转义形式）。
        $bodyParams = http_build_query($this->redact($request->all()));
        if (! $skipBody) {
            $rawBody = json_encode($this->redact($request->all()), JSON_UNESCAPED_UNICODE);
        }
        $headers    = json_encode(
            $this->redact($request->headers->all()),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $method   = strtolower($request->getMethod());
        $url      = $path;
        $respBody = $response !== null
            ? (string) $response->getContent()
            : ($error !== null ? '{"error":"' . str_replace('"', '\\"', $error->getMessage()) . '"}' : '');

        $decoded = json_decode($respBody, true);
        if (is_array($decoded)) {
            $respBody = json_encode($this->redact($decoded), JSON_UNESCAPED_UNICODE);
        }

        // response 截断 4 KB，避免大体量响应撑爆日志
        if (strlen($respBody) > 4096) {
            $respBody = substr($respBody, 0, 4096) . '...<truncated>';
        }

        // IP 与 trace_id 由 LogFormatter 写到前缀
        // （见 [datetime][trace_id][LEVEL][ip]），因此 message 体只承担
        // URL/STATUS/METHOD/BODY_PARAMS/RAW_BODY/HEADERS/RESPONSE 这些字段。
        // 与 novel-api 一致，原样输出（不做 addslashes）—— 任何 JSON 编码
        // 都是日志收集器的工作，人眼 + grep 看原值更直观。
        $log = sprintf(
            "URL: '%s', STATUS_CODE: '%d', METHOD: '%s', BODY_PARAMS: '%s', RAW_BODY: '%s', HEADERS: '%s'; RESPONSE: '%s'",
            $url,
            $status,
            $method,
            $bodyParams,
            (string) $rawBody,
            $headers,
            $respBody,
        );

        // 日志等级选择
        $level = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default        => 'info',
        };

        try {
            Log::channel('access')->log($level, $log);
        } catch (Throwable $e) {
            // 日志写入失败不能影响业务请求
        }
    }

    /**
     * 判断指定路径是否需要跳过 body 记录。
     *
     * @param  string  $path  请求路径
     * @return bool           需要跳过返回 true
     */
    private function shouldSkipBody(string $path): bool
    {
        foreach (self::SKIP_BODY_PATHS as $skip) {
            if (str_starts_with($path, $skip)) {
                return true;
            }
        }

        return false;
    }
}
