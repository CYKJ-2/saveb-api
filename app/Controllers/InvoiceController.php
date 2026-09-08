<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoice 订单接口：负责参数校验、调用服务和封装响应。
 */
class InvoiceController
{
    public function __construct(private InvoiceService $invoiceService)
    {
    }

    /**
     * 查询列表。
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->invoiceService->listing($request->validate([
            'keyword' => 'nullable|string|max:255',
            'startDate' => 'nullable|date_format:Y-m-d',
            'endDate' => 'nullable|date_format:Y-m-d|after_or_equal:startDate',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ])));
    }

    /**
     * 读取详情。
     */
    public function show(int $id): JsonResponse
    {
        return AppResponse::success($this->invoiceService->find($id));
    }

    /**
     * 计算下一个订单号。
     */
    public function nextNumber(): JsonResponse
    {
        return AppResponse::success(['number' => $this->invoiceService->nextNumber()]);
    }

    /** 读取录入表单的客服选项和换算汇率，无需首页或用户管理权限。 */
    public function formOptions(): JsonResponse
    {
        return AppResponse::success($this->invoiceService->formOptions());
    }

    /** 粘贴内容使用与截图相同的字段解析，不上传图片，也不保存订单。 */
    public function parseText(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|max:30000']);

        return AppResponse::success($this->invoiceService->parseText($data['text']));
    }

    /**
     * 导出操作日志。
     */
    public function exportLogs(): StreamedResponse
    {
        return \App\Common\CsvResponse::download(
            $this->invoiceService->exportLogs(),
            ['operationTime' => 'Operation Time', 'operator' => 'Operator', 'action' => 'Action',
                'orderNumber' => 'Order Number', 'customer' => 'Customer', 'changedFields' => 'Changed Fields',
                'details' => 'Details', 'recordId' => 'Record ID'],
            'invoice-operation-logs.csv',
        );
    }

    /**
     * 分页读取操作日志。
     */
    public function logs(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules());

        return AppResponse::success($this->invoiceService->logs($validatedData['page'] ?? 1, $validatedData['per_page'] ?? 20));
    }

    /**
     * 保存记录。
     */
    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'version' => ($id ? 'required' : 'sometimes') . '|integer|min:1',
            'invoice_date' => 'required|date_format:Y-m-d',
            'order_date' => 'nullable|date_format:Y-m-d',
            'invoice_status' => 'sometimes|required|in:Paid,Pending,Overdue,Unpaid,Refunded,Failed',
            'customer_full_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'phone_number' => 'nullable|string|max:64',
            'country' => 'nullable|string|max:128',
            'address' => 'nullable|string|max:3000',
            'invoice_link' => 'required|url:http,https|max:2000',
            'recipient_paypal' => 'required|email|max:255',
            'amount_usd' => 'required|numeric|gt:0|max:10000000|decimal:0,2',
            'expedited_shipping' => 'required|boolean',
            'gift_box' => 'required|in:Has,None',
            'fixed_discount' => 'nullable|numeric|min:0|max:10000000|decimal:0,2',
            'percentage_discount' => 'nullable|numeric|min:0|lte:100',
            'invoice_screenshot_attachment_id' => 'required|integer|min:1',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_name' => 'required|string|max:2000',
            'items.*.description' => 'nullable|string|max:4000',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
            'items.*.price' => 'required|numeric|min:0|max:10000000|decimal:0,2',
            'items.*.notes' => 'nullable|string|max:4000',
            'items.*.image_attachment_id' => 'nullable|integer|min:1',
            'allocations' => 'required|array|min:1|max:30',
            'allocations.*.staff_code' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/',
            'allocations.*.percent' => 'required|numeric|gt:0|lte:100',
            'allocations.*.commission_percent' => 'nullable|numeric|min:0|lte:100',
        ]);

        return AppResponse::success($this->invoiceService->save($id, $data, (int) $request->attributes->get('auth_user')->id));
    }

    /**
     * 删除记录。
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $validatedData = $request->validate(['version' => 'required|integer|min:1']);
        $this->invoiceService->remove($id, $validatedData['version'], (int) $request->attributes->get('auth_user')->id);

        return AppResponse::success();
    }
}
