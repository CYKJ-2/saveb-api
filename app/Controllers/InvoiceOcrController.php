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
    public function __construct(private InvoiceOcrService $invoiceOcrService)
    {
    }

    /**
     * 识别截图文字。
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
