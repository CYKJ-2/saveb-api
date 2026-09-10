<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\InvoiceOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invoice 截图识别接口：负责参数校验、调用服务和封装响应。
 */
class InvoiceOcrController
{
    /**
     * 注入 Invoice 截图识别处理所需的依赖。
     *
     * @param  InvoiceOcrService  $invoiceOcrService  Invoice 截图识别业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private InvoiceOcrService $invoiceOcrService)
    {
    }

    /**
     * 识别截图文字。
     *
     * 请求字段（校验规则）：
     * - attachmentId：'required|integer|min:1'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含OCR 引擎结果与可填入 Invoice 表单的字段建议
     * @see InvoiceOcrService::recognize()
     */
    public function recognize(Request $request): JsonResponse
    {
        $validatedData = $request->validate(['attachmentId' => 'required|integer|min:1']);
        $codes = $request->attributes->get('auth_permission_codes', []);

        return AppResponse::success($this->invoiceOcrService->recognize(
            $validatedData['attachmentId'],
            (int) $request->attributes->get('auth_user')->id,
            (bool) array_intersect(['*', 'business.invoice.list'], $codes),
        ));
    }
}
