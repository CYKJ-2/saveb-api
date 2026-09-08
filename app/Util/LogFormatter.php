<?php

namespace App\Util;

use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

/**
 * 自定义 Monolog 单行日志格式化器——与 novel-api/app/util/LogFormatterUtil.php
 * 配合 LogFileHandlerUtil::write() 的行为保持一致。
 *
 * 最终输出格式（每条日志一行）：
 *
 *   [datetime][trace_id][LEVEL][ip] <message> <context> <extra>
 *
 *   示例（与 novel-api 一致）：
 *     [2026-07-17 06:40:09][83d0982f6b1e4067b1d4a8babcfc5527][INFO][192.168.10.131]URL: '/ping', STATUS_CODE: '200', METHOD: 'GET', ...
 *
 * 为什么 `[trace_id]` 和 `[ip]` 放在 formatter 而非 message 中？
 * ──────────────────────────────────────────────────────────────
 * novel-api 的 `LogFileHandlerUtil::write()` 在已经格式化的字符串字节偏移 21 处
 * （即 `[YYYY-MM-DD HH:MM:SS]` 之后）直接拼接 `[trace_id]`，
 * 这很优雅但需要 override `RotatingFileHandler::write()`，
 * 而 Monolog v3 中该方法是 `protected` 且无法从类外调用。
 *
 * 在 formatter 中实现同样能产出完全一致的输出，同时兼容 Laravel 的 `daily` 驱动
 * （无需自定义 handler）。
 *
 * `[ip]` 槽位与 novel-api 对称放置，但 IP **仅在有活跃请求时注入**；
 * CLI / queue 任务渲染为 `-`，保持列对齐但不产生虚假数据。
 */
class LogFormatter extends LineFormatter
{
    /**
     * 构造格式化器。
     */
    public function __construct()
    {
        $format = "[%datetime%][%extra.trace_id%][%level_name%][%extra.ip%] %message% %context% %extra%\n";
        parent::__construct(
            format: $format,
            dateFormat: 'Y-m-d H:i:s',
            allowInlineLineBreaks: true,
            ignoreEmptyContextAndExtra: true,
            includeStacktraces: true,
        );
    }

    /**
     * 在 format() 遍历 token 前解析 trace_id 和 ip。
     *
     * LineFormatter 的 format() 只在 `extra`/`context` 中查找 `%foo%` 占位符，
     * 因此我们在一次调用期间将值发布到 `extra` 中供占位符消费。
     *
     * @param  LogRecord  $record  原始日志记录
     * @return string              格式化后的单行字符串
     */
    public function format(LogRecord $record): string
    {
        $traceId = TraceContext::resolve();

        // 在 HTTP 上下文取请求 IP；非 HTTP 上下文渲染为 `-`（列对齐）
        $ip = '-';
        try {
            $request = request();
            if ($request) {
                $ip = (string) $request->ip();
            }
        } catch (\Throwable $e) {
            // `request()` 在非 HTTP 上下文抛出 —— 保持 `-`
        }

        // 注入为 extra 字段（供 %trace_id% / %ip% 占位符消费，
        // 之后由 LineFormatter 从 extra 中 unset()，避免行尾重复打印）
        $extra = $record->extra;
        $extra['trace_id'] = $traceId;
        $extra['ip']       = $ip;

        $record = $record->with(extra: $extra);

        return parent::format($record);
    }
}
