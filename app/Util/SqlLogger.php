<?php

namespace App\Util;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 捕获请求期间执行的每条 SQL 语句，写入 `sql` 通道
 * (`storage/logs/sql-YYYY-MM-DD.log`)。
 *
 * 最终行格式（由 LogFormatter 统一前缀 + 本监听器决定）：
 *
 *   [YYYY-MM-DD HH:MM:SS][trace_id][LEVEL][ip] [12.34ms] select * from "users" where id = 5
 *
 * 与 novel-api/app/bootstrap/DbLogListenerBootstrap.php 完全一致——
 * 紧凑、单行、前缀毫秒、bindings 插值。
 *
 * 慢查询（>200ms）同时写入 `sql_error`，便于快速定位瓶颈。
 *
 * {@see \App\Util\LogFormatter} 会把同一 32 位 md5 trace_id 写入所有记录前缀
 * （与 novel-api 一致），因此 `grep <trace_id> sql.log access.log`
 * 可完整还原一次请求。
 */
class SqlLogger
{
    /** 慢查询阈值（毫秒）；超过此值的查询同时写入 sql_error */
    public const SLOW_QUERY_MS = 200;

    /** 标记是否已注册（避免重复注册） */
    private static bool $registered = false;

    /**
     * 注册 SQL 监听器。幂等：多次调用只注册一次。
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        DB::listen(function (QueryExecuted $query) {
            self::record($query);
        });
    }

    /**
     * 将单条 QueryExecuted 事件格式化并分发到日志通道。
     *
     * @param  QueryExecuted  $query  数据库查询事件
     */
    public static function record(QueryExecuted $query): void
    {
        $sql = trim($query->sql);

        // 跳过连接检测查询
        if (strcasecmp($sql, 'select 1') === 0) {
            return;
        }

        $bindings = $query->bindings ?? [];
        $timeMs   = (float) $query->time;
        $execute  = preg_match('/password|token_hash|api_tokens/i', $sql)
            ? $sql : self::interpolate($sql, $bindings);

        // 紧凑格式（与 novel-api 一致）："[12.34ms] select * from ..."
        $message = sprintf('[%.2fms] %s', $timeMs, $execute);

        try {
            Log::channel('sql')->info($message);
        } catch (\Throwable $e) {
            // 日志写入失败不能影响业务请求
        }

        if ($timeMs >= self::SLOW_QUERY_MS) {
            try {
                Log::channel('sql_error')->warning($message . sprintf(' [threshold=%dms]', self::SLOW_QUERY_MS));
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * 将 SQL 中的 `?` 占位符安全替换为 bindings。
     *
     * 与 novel-api/app/bootstrap/DbLogListenerBootstrap.php 一致——
     * 数字 bindings 保留原值，其他用双引号包裹。
     *
     * @param  string  $sql       原始 SQL（含 ? 占位符）
     * @param  array   $bindings  参数绑定值
     * @return string             插值后的可执行 SQL 字符串
     */
    private static function interpolate(string $sql, array $bindings): string
    {
        if (empty($bindings)) {
            return $sql;
        }
        $rendered = [];
        foreach ($bindings as $v) {
            if (is_numeric($v)) {
                $rendered[] = (string) $v;
            } else {
                $rendered[] = '"' . str_replace('"', '\\"', (string) $v) . '"';
            }
        }

        return Str::replaceArray('?', $rendered, $sql);
    }
}
