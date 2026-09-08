<?php

namespace App\Common;

/**
 * 统一的响应码与消息常量。
 *
 * 错误码分段：
 *   0           = 成功
 *   -1000~-1099 = 系统错误
 *   -1100~-1199 = 认证错误
 *   -1200~-1299 = 用户错误
 *   -1300~-1399 = 参数校验错误
 *   -2000~-2999 = 业务错误（具体操作专属）
 *
 * 所有错误码均为整数；所有消息均为字符串。
 * 通过 RespDef::CODE_* 与 RespDef::MSG_* 常量引用。
 */
class RespDef
{
    /* ─── Success ─────────────────────────────────────────── */
    public const int CODE_SUCCESS = 0;

    public const string MSG_SUCCESS = '操作成功。';

    /* ─── System (-1000~-1099) ───────────────────────────── */
    public const int CODE_SYSTEM_ERROR     = -1000;

    public const int CODE_NO_API           = -1001;

    public const int CODE_DB_ERROR         = -1002;

    public const int CODE_REDIS_ERROR      = -1003;

    public const int CODE_TOKEN_EXPIRED    = -1004;

    public const int CODE_TOKEN_INVALID    = -1005;

    public const int CODE_FORBIDDEN        = -1006;

    public const int CODE_NOT_FOUND        = -1007;

    public const string MSG_SYSTEM_ERROR    = '系统错误。';

    public const string MSG_NO_API          = '接口不存在。';

    public const string MSG_DB_ERROR        = '系统繁忙，请稍后重试。';

    public const string MSG_REDIS_ERROR     = '系统繁忙，请稍后重试。';

    public const string MSG_TOKEN_EXPIRED   = 'Token 已过期，请重新登录。';

    public const string MSG_TOKEN_INVALID   = '无效的 Token。';

    public const string MSG_FORBIDDEN       = '您没有执行该操作的权限。';

    public const string MSG_NOT_FOUND       = '资源不存在。';

    /* ─── Auth (-1100~-1199) ─────────────────────────────── */
    public const int CODE_INVALID_CREDENTIALS = -1100;

    public const int CODE_ACCOUNT_DISABLED    = -1101;

    public const int CODE_ACCOUNT_BANNED      = -1102;

    public const int CODE_MUST_CHANGE_PWD     = -1103;

    public const int CODE_TOKEN_NOT_FOUND     = -1104;

    public const int CODE_INVALID_PASSWORD    = -1105;

    public const int CODE_USER_LOCKED         = -1106;

    public const string MSG_INVALID_CREDENTIALS = '用户名或密码错误。';

    public const string MSG_ACCOUNT_DISABLED    = '账户已禁用。';

    public const string MSG_ACCOUNT_BANNED     = '账户已被封禁。';

    public const string MSG_MUST_CHANGE_PWD    = '请先修改密码后再继续操作。';

    public const string MSG_TOKEN_NOT_FOUND     = 'Token 不存在。';

    public const string MSG_INVALID_PASSWORD    = '密码错误。';

    public const string MSG_USER_LOCKED         = '账户已锁定。';

    /* ─── User (-1200~-1299) ─────────────────────────────── */
    public const int CODE_USER_NOT_FOUND    = -1200;

    public const int CODE_USER_ALREADY_EXISTS = -1201;

    public const int CODE_ROLE_NOT_ALLOWED  = -1202;

    public const string MSG_USER_NOT_FOUND     = '用户不存在。';

    public const string MSG_USER_ALREADY_EXISTS = '用户名已存在。';

    public const string MSG_ROLE_NOT_ALLOWED   = '当前角色不允许执行该操作。';

    /* ─── Validation / Params (-1300~-1399) ───────────────── */
    public const int CODE_MISSING_PARAMS  = -1300;

    public const int CODE_INVALID_PARAMS   = -1301;

    public const int CODE_VALIDATION_FAIL  = -1302;

    public const string MSG_MISSING_PARAMS  = '缺少必要参数。';

    public const string MSG_INVALID_PARAMS  = '参数取值不合法。';

    public const string MSG_VALIDATION_FAIL = '参数校验未通过。';

    /* ─── Business (-2000~-2999) ──────────────────────────── */
    public const int CODE_OPERATION_FAILED  = -2000;

    public const int CODE_DATA_NOT_FOUND   = -2001;

    public const int CODE_DATA_EXISTS      = -2002;

    public const int CODE_OPERATION_LIMIT  = -2003;  // e.g. rate-limit / cooldown

    public const string MSG_OPERATION_FAILED = '操作失败，请重试。';

    public const string MSG_DATA_NOT_FOUND   = '数据不存在。';

    public const string MSG_DATA_EXISTS     = '数据已存在。';

    public const string MSG_OPERATION_LIMIT  = '请求过于频繁，请稍后再试。';

    /* ─── Orders (-2200~-2299) ─────────────────────────── */
    public const int CODE_ORDER_NOT_FOUND      = -2200;

    public const int CODE_ORDER_ALREADY_EXISTS = -2201;

    public const string MSG_ORDER_NOT_FOUND      = '订单不存在。';

    public const string MSG_ORDER_ALREADY_EXISTS = '订单已存在。';

    /* ─── RBAC (-2100~-2299) ──────────────────────────── */
    public const int CODE_PERMISSION_NOT_FOUND   = -2104;

    public const int CODE_PERMISSION_ALREADY_EXISTS = -2105;

    public const int CODE_PERMISSION_DENIED = -2106;

    public const int CODE_ROLE_HAS_USERS     = -2107;

    public const int CODE_SYSTEM_ROLE_PROTECTED = -2108;

    public const int CODE_ROLE_NOT_FOUND = -2109;

    public const string MSG_ROLE_NOT_FOUND     = '角色不存在。';

    public const string MSG_ROLE_ALREADY_EXISTS = '角色代码已存在。';

    public const string MSG_PERMISSION_NOT_FOUND   = '权限节点不存在。';

    public const string MSG_PERMISSION_ALREADY_EXISTS = '权限代码已存在。';

    public const string MSG_PERMISSION_DENIED = '您没有执行该操作的权限。';

    public const string MSG_ROLE_HAS_USERS     = '该角色仍挂载在用户身上，无法删除。';

    public const string MSG_SYSTEM_ROLE_PROTECTED = '系统内置角色不可修改或删除。';
}
