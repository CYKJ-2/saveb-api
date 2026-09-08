<?php

namespace App\Common;

use App\Exceptions\SystemException;
use Illuminate\Http\JsonResponse;

/**
 * 统一 JSON 响应辅助类。
 *
 * 响应格式：
 *   成功：{ "success": true,  "code": 0,     "message": "ok",        "data": {...} }
 *   失败：{ "success": false, "code": -1200, "message": "...",       "data": null  }
 */
class AppResponse
{
    /**
     * 200 — 成功响应，可附带 data 与 meta。
     *
     * @param  mixed        $data    业务数据
     * @param  string|null  $message 自定义成功消息；为空时使用 RespDef::MSG_SUCCESS
     * @param  int          $code    响应业务码，默认 0
     * @param  array        $meta    顶层附加字段，例如 locale、total 等
     * @return JsonResponse         200 OK 响应
     */
    public static function success($data = null, ?string $message = null, int $code = RespDef::CODE_SUCCESS, array $meta = [], int $httpStatus = 200): JsonResponse
    {
        $body = [
            'success' => true,
            'code'    => $code,
            'message' => $message ?? RespDef::MSG_SUCCESS,
            'data'    => $data,
        ];
        // 把 meta 合入顶层（避开 success/code/message/data 这些保留字段）
        foreach ($meta as $k => $v) {
            if (! in_array($k, ['success', 'code', 'message', 'data'], true)) {
                $body[$k] = $v;
            }
        }

        return new JsonResponse($body, $httpStatus);
    }

    /**
     * 自定义 HTTP 状态 — 错误响应。
     *
     * @param  int         $code        RespDef::CODE_* 业务错误码
     * @param  string|null $message     覆盖 RespDef 中的默认消息
     * @param  int|null    $httpStatus  HTTP 状态码；为 null 时由 code 自动映射
     * @return JsonResponse             错误响应
     */
    public static function error(int $code, ?string $message = null, ?int $httpStatus = null): JsonResponse
    {
        $status = $httpStatus ?? SystemException::defaultHttpStatus($code);

        return new JsonResponse([
            'success' => false,
            'code'    => $code,
            'message' => $message ?? SystemException::from($code)->getMessage(),
            'data'    => null,
        ], $status);
    }

    /**
     * 直接返回自定义 JSON 响应体。
     *
     * @param  array  $body   完整响应体
     * @param  int    $status HTTP 状态码
     * @return JsonResponse
     */
    public static function json(array $body, int $status = 200): JsonResponse
    {
        return new JsonResponse($body, $status);
    }
}
