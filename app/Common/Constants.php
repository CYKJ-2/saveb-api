<?php

namespace App\Common;

/**
 * 应用常量定义。
 *
 * PHP 8.3+ 类型化常量：
 *   const int FOO = 1;
 *   const string BAR = 'baz';
 */
class Constants
{
    /* ─── 分页默认值 ──────────────────────────────── */
    /** 默认页码（1-based） */
    public const int PAGE          = 1;

    /** 默认每页条数 */
    public const int PER_PAGE      = 20;

    /* ─── 认证 ──────────────────────────────────── */
    /** Token 默认有效期（秒），7 天，与 ERP 会话策略一致 */
    public const int TOKEN_TTL = 86400 * 7;

    /* ─── 订阅周期类型 ──────────────────────────── */
    public const int PERIOD_TYPE_DAY   = 1;

    public const int PERIOD_TYPE_WEEK  = 2;

    public const int PERIOD_TYPE_MONTH = 3;

    public const int PERIOD_TYPE_YEAR  = 4;

    /** 周期类型 ID → 字符串名称映射 */
    public static array $periodTypeMap = [
        self::PERIOD_TYPE_DAY   => 'DAY',
        self::PERIOD_TYPE_WEEK  => 'WEEK',
        self::PERIOD_TYPE_MONTH => 'MONTH',
        self::PERIOD_TYPE_YEAR  => 'YEAR',
    ];
}
