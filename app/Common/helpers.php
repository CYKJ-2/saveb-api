<?php

use App\Util\TraceContext;

if (! function_exists('get_trace_id')) {
    /**
     * 获取当前请求 / CLI 进程的 Trace-Id。
     *
     * 在 HTTP 请求中由 App\Middleware\TraceId 从 X-Trace-Id /
     * X-Correlation-Id 头设置（缺失时自动生成）。
     * 在 HTTP 上下文外返回一个稳定的 md5 十六进制字符串。
     *
     * @return string  Trace-Id 字符串
     */
    function get_trace_id(): string
    {
        return TraceContext::resolve();
    }
}

if (! function_exists('get_cli_trace_id')) {
    /**
     * 获取当前进程的 CLI trace id（首次调用时创建）。
     *
     * @return string  CLI Trace-Id
     */
    function get_cli_trace_id(): string
    {
        return TraceContext::cliTraceId();
    }
}

if (! function_exists('reset_cli_trace_id')) {
    /**
     * 清除缓存的 CLI trace id（用于测试场景）。
     */
    function reset_cli_trace_id(): void
    {
        TraceContext::resetCliTraceId();
    }
}
