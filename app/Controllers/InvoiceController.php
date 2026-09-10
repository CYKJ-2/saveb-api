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
    /**
     * 注入 Invoice 订单处理所需的依赖。
     *
     * @param  InvoiceService  $invoiceService  Invoice 订单业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private InvoiceService $invoiceService)
    {
    }

    /**
     * 查询 Invoice 订单列表。
     *
     * 请求字段（校验规则）：
     * - keyword：'nullable|string|max:255'
     * - startDate：'nullable|date_format:Y-m-d'
     * - endDate：'nullable|date_format:Y-m-d|after_or_equal:startDate'
     * - page：'nullable|integer|min:1'
     * - per_page：'sometimes|integer|min:1|max:100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含Invoice 订单列表及相应分页信息
     * @see InvoiceService::listing()
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
     * 读取 Invoice 订单详情及截图、商品图片的内联预览。
     *
     * @param  int  $id  Invoice 订单记录主键 ID
     * @return JsonResponse 统一 JSON 响应；data 包含Invoice 订单详情，附带截图和各商品图片的内联预览信息
     * @see InvoiceService::find()
     */
    public function show(int $id): JsonResponse
    {
        return AppResponse::success($this->invoiceService->find($id));
    }

    /**
     * 计算下一个订单号。
     *
     * @return JsonResponse 统一 JSON 响应；data 包含下一个可用的数字 Invoice 订单号，最小为 10000
     * @see InvoiceService::nextNumber()
     */
    public function nextNumber(): JsonResponse
    {
        return AppResponse::success(['number' => $this->invoiceService->nextNumber()]);
    }

    /**
     * 读取录入表单的客服选项和换算汇率，无需首页或用户管理权限。
     *
     * @return JsonResponse 统一 JSON 响应；data 为Invoice 订单的业务结果
     * @see InvoiceService::formOptions()
     */
    public function formOptions(): JsonResponse
    {
        return AppResponse::success($this->invoiceService->formOptions());
    }

    /**
     * 粘贴内容使用与截图相同的字段解析，不上传图片，也不保存订单。
     *
     * 请求字段（校验规则）：
     * - text：'required|string|max:30000'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含解析后的表单字段建议、原币金额、付款状态和识别提示
     * @see InvoiceService::parseText()
     */
    public function parseText(Request $request): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|max:30000']);

        return AppResponse::success($this->invoiceService->parseText($data['text']));
    }

    /**
     * 导出操作日志。
     *
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see InvoiceService::exportLogs()
     */
    public function exportLogs(): StreamedResponse
    {
        return \App\Common\CsvResponse::download(
            $this->invoiceService->exportLogs(\App\Common\ExportHeaders::locale()),
            ['operationTime' => 'Operation Time', 'operator' => 'Operator', 'actionLabel' => 'Action',
                'orderNumber' => 'Order Number', 'customer' => 'Customer', 'changedFields' => 'Changed Fields',
                'details' => 'Details', 'recordId' => 'Record ID'],
            'invoice-operation-logs.csv',
        );
    }

    /**
     * 分页读取操作日志。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse data.data 为日志列表，含与导出一致的展示字段；locale 控制操作名称的中英文
     * @see InvoiceService::logs()
     */
    public function logs(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules() + ['locale' => 'nullable|in:zh-CN,en-US']);

        return AppResponse::success($this->invoiceService->logs(
            $validatedData['page'] ?? 1,
            $validatedData['per_page'] ?? 20,
            $validatedData['locale'] ?? 'zh-CN',
        ));
    }

    /**
     * 保存 Invoice 订单及其关联数据。
     *
     * 请求字段（校验规则）：
     * - version：($id ? 'required' : 'sometimes') . '|integer|min:1'
     * - invoice_date：'required|date_format:Y-m-d'
     * - order_date：'nullable|date_format:Y-m-d'
     * - invoice_status：'sometimes|required|in:Paid,Pending,Overdue,Unpaid,Refunded,Failed'
     * - customer_full_name：'required|string|max:255'
     * - customer_email：'required|email|max:255'
     * - phone_number：'nullable|string|max:64'
     * - country：'nullable|string|max:128'
     * - address：'nullable|string|max:3000'
     * - invoice_link：'required|url:http,https|max:2000'
     * - recipient_paypal：'required|email|max:255'
     * - amount_usd：'required|numeric|gt:0|max:10000000|decimal:0,2'
     * - expedited_shipping：'required|boolean'
     * - gift_box：'required|in:Has,None'
     * - fixed_discount：'nullable|numeric|min:0|max:10000000|decimal:0,2'
     * - percentage_discount：'nullable|numeric|min:0|lte:100'
     * - invoice_screenshot_attachment_id：'required|integer|min:1'
     * - items：'required|array|min:1|max:100'
     * - items.*.product_name：'required|string|max:2000'
     * - items.*.description：'nullable|string|max:4000'
     * - items.*.quantity：'required|integer|min:1|max:10000'
     * - items.*.price：'required|numeric|min:0|max:10000000|decimal:0,2'
     * - items.*.notes：'nullable|string|max:4000'
     * - items.*.image_attachment_id：'nullable|integer|min:1'
     * - allocations：'required|array|min:1|max:30'
     * - allocations.*.staff_code：'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/'
     * - allocations.*.percent：'required|numeric|gt:0|lte:100'
     * - allocations.*.commission_percent：'nullable|numeric|min:0|lte:100'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int|null  $id  Invoice 订单记录主键 ID；null 表示新增
     * @return JsonResponse 统一 JSON 响应；data 包含保存后的 Invoice 订单及商品、客服分摊字段
     * @see InvoiceService::save()
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
     * 删除 Invoice 订单记录。
     *
     * 请求字段（校验规则）：
     * - version：'required|integer|min:1'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int  $id  Invoice 订单记录主键 ID
     * @return JsonResponse 操作成功的统一 JSON 响应
     * @see InvoiceService::remove()
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $validatedData = $request->validate(['version' => 'required|integer|min:1']);
        $this->invoiceService->remove($id, $validatedData['version'], (int) $request->attributes->get('auth_user')->id);

        return AppResponse::success();
    }
}
