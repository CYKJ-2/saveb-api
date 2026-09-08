<?php

namespace App\Util;

/**
 * 进程级当前 Trace-Id 持有者。
 *
 * 格式：32 位十六进制（md5 风格）—— 与 novel-api 完全一致，
 * 因此 saveb-api 中的 trace_id 可在任意跨系统日志收集器中
 * 与 novel-api 的 trace_id 一起 grep 匹配。
 *
 * 解析顺序（首个非空胜出）：
 *   1. TraceId 中间件在当前 Request 上设置的值
 *      （接受 X-Trace-Id / X-Correlation-Id / X-Request-Id 或自动生成）
 *   2. trace_id 查询 / body 参数
 *   3. CLI / queue 回退 —— 按进程生成 md5(time() . mt_rand())
 *   4. 全新 md5(now)（仅在以上均不适用时，例如早期启动阶段）
 *
 * 同一请求内 trace_id 在 sql / sql_error / access / error / laravel
 * 各通道间共享，因此 `grep <trace_id> *.log` 可查看该请求的所有行为。
 */
class TraceContext
{
    /** CLI / queue / 定时任务回退用的进程级 fallback */
    private static ?string $cliTraceId = null;

    /** 由 TraceId 中间件在每个请求上设置 */
    private static ?string $currentTraceId = null;

    /**
     * 由 TraceId 中间件调用，注入当前请求的 Trace-Id。
     *
     * @param  string|null  $traceId  Trace-Id
     */
    public static function setCurrentTraceId(?string $traceId): void
    {
        self::$currentTraceId = $traceId;
    }

    /**
     * 获取当前生效的 Trace-Id。
     *
     * @return string  32 位十六进制 Trace-Id
     */
    public static function resolve(): string
    {
        // 1. 中间件显式设置的值（测试时也可手动设置）
        if (self::$currentTraceId !== null && self::$currentTraceId !== '') {
            return self::$currentTraceId;
        }

        // 2. 当前 Laravel 请求（由 TraceId 中间件设置）
        try {
            $request = request();
            if ($request && $request->attributes->has(\App\Middleware\TraceId::ATTR)) {
                return (string) $request->attributes->get(\App\Middleware\TraceId::ATTR);
            }
            // 中间件未运行时兜底读取 header
            if ($request && $hdr = $request->header('X-Trace-Id')) {
                return (string) $hdr;
            }
        } catch (\Throwable $e) {
            // `request()` 在非 HTTP 上下文抛出 —— 继续往后走
        }

        // 3. CLI / queue 回退 —— md5 风格 32 位 hex
        if (self::$cliTraceId === null) {
            self::$cliTraceId = md5((string) microtime(true) . mt_rand(100000, 999999));
        }

        return self::$cliTraceId;
    }

    /**
     * 获取 CLI Trace-Id（委托给 resolve）。
     *
     * @return string  CLI Trace-Id
     */
    public static function cliTraceId(): string
    {
        return self::resolve();
    }

    /**
     * 重置 CLI Trace-Id（用于测试重置）。
     */
    public static function resetCliTraceId(): void
    {
        self::$cliTraceId = null;
        self::$currentTraceId = null;
    }
}
