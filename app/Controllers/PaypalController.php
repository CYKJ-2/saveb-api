<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\CsvResponse;
use App\Services\PaypalOperationLogService;
use App\Services\PaypalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PayPal 账户接口：负责参数校验、调用服务和封装响应。
 */
class PaypalController
{
    /**
     * 注入 PayPal 账户处理所需的依赖。
     *
     * @param  PaypalService  $paypalService  PayPal 账户业务服务
     * @param  PaypalOperationLogService  $paypalOperationLogService  PayPal 操作记录分页及导出服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private PaypalService $paypalService,
        private PaypalOperationLogService $paypalOperationLogService,
    ) {
    }

    /**
     * 导出 PayPal 账户的完整筛选结果。
     *
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see PaypalService::listing()
     */
    public function export(): StreamedResponse
    {
        return \App\Common\CsvResponse::download(
            $this->paypalService->listing(),
            [
                'email' => 'Email',
                'accountName' => 'Account Name',
                'addedDate' => 'Date Added',
                'balance' => 'Balance USD',
                'received' => 'Total Received USD',
                'withdrawn' => 'Total Withdrawn USD',
                'reviews' => 'Reviews',
            ],
            'paypal-accounts.csv',
        );
    }

    /**
     * 查询 PayPal 账户列表。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含PayPal 账户列表及相应分页信息
     * @see PaypalService::page()
     */
    public function index(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules() + ['keyword' => 'nullable|string|max:255', 'sort' => 'sometimes|in:latestIncomingAt,received,balance,reviews,withdrawn', 'threshold' => 'sometimes|numeric|min:0']);

        return AppResponse::success($this->paypalService->page($validatedData));
    }

    /**
     * 查询关联订单。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含PayPal 账户列表及相应分页信息
     * @see PaypalService::orderPage()
     */
    public function orders(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules() + ['email' => 'required|email|max:255']);

        return AppResponse::success($this->paypalService->orderPage($validatedData));
    }

    /**
     * 查询提款记录与范围内合计。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为PayPal 账户的业务结果
     * @see PaypalService::withdrawalPage()
     */
    public function withdrawals(Request $request): JsonResponse
    {
        return AppResponse::success($this->paypalService->withdrawalPage($this->dateFilters($request) + $request->validate(\App\Common\PageResult::rules())));
    }

    /**
     * 查询按日或按月汇总的提款金额。
     *
     * 请求字段（校验规则）：
     * - mode：'required|in:daily,monthly'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含按 period 排序的日或月提款金额列表，amount 保留两位小数
     * @see PaypalService::withdrawalStatistics()
     */
    public function statistics(Request $request): JsonResponse
    {
        $filters = $this->dateFilters($request);
        $filters += $request->validate(['mode' => 'required|in:daily,monthly']);

        return AppResponse::success($this->paypalService->withdrawalStatistics($filters));
    }

    /**
     * 导出当前账户的收款订单。
     *
     * 请求字段（校验规则）：
     * - email：'required|email|max:255'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see PaypalService::orders()
     */
    public function exportOrders(Request $request): StreamedResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);

        return CsvResponse::download($this->paypalService->orders($data['email']), [
            'createTime' => 'Order Time', 'clientOrderId' => 'Order ID', 'paypalOrderId' => 'PayPal Order ID',
            'customerFullName' => 'Customer Full Name', 'clientSite' => 'Source Site',
            'classification' => 'Classification', 'paymentStatus' => 'Order Status',
            'amount' => 'Amount', 'currency' => 'Currency',
        ], 'paypal-received-orders.csv');
    }

    /**
     * 分页查询 PayPal 操作记录，合并原平台与新系统日志。
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param Request $request 当前 HTTP 请求；locale 为 zh-CN 或 en-US，默认中文
     * @return JsonResponse data 包含 list、total、page、per_page、last_page，字段与导出一致
     * @see PaypalOperationLogService::page()
     */
    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate(\App\Common\PageResult::rules() + ['locale' => 'nullable|in:zh-CN,en-US']);

        return AppResponse::success($this->paypalOperationLogService->page($filters));
    }

    /**
     * 导出全部账户修改日志，账号名、操作人和字段格式与分页列表一致。
     *
     * 请求字段（校验规则）：
     * - locale：'nullable|in:zh-CN,en-US'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see PaypalOperationLogService::export()
     */
    public function exportLogs(Request $request): StreamedResponse
    {
        $data = $request->validate(['locale' => 'nullable|in:zh-CN,en-US']);
        $english = ($data['locale'] ?? '') === 'en-US';

        return CsvResponse::download($this->paypalOperationLogService->export($data['locale'] ?? 'zh-CN'), [
            'time' => $english ? 'Time' : '时间',
            'field' => $english ? 'Field' : '字段',
            'action' => $english ? 'Action' : '操作',
            'accountName' => $english ? 'Account Name' : '账号名',
            'email' => $english ? 'Email' : '邮箱',
            'previous' => $english ? 'Previous Value' : '修改前',
            'current' => $english ? 'New Value' : '修改后',
            'delta' => $english ? 'Delta' : '变更量',
            'actor' => $english ? 'Updated By' : '修改人',
        ], 'paypal-change-log.csv');
    }

    /**
     * 导出完整筛选范围的提款记录，与列表分页无关。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see PaypalService::withdrawals()
     */
    public function exportWithdrawals(Request $request): StreamedResponse
    {
        return CsvResponse::download($this->paypalService->withdrawals($this->dateFilters($request))['rows'], [
            'date' => 'Date', 'accountName' => 'Account Name', 'email' => 'Email',
            'amount' => 'Withdrawal Amount', 'source' => 'Source',
        ], 'paypal-withdrawals.csv');
    }

    /**
     * 校验可选日期范围和账户关键词，列表及导出共用。
     *
     * 请求字段（校验规则）：
     * - keyword：'nullable|string|max:255'
     * - startDate：'nullable|date_format:Y-m-d'
     * - endDate：'nullable|date_format:Y-m-d' . ($request->filled('startDate') ? '|after_or_equal:startDate' : '')
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return array 通过校验的日期范围、账户关键字和分页参数
     */
    private function dateFilters(Request $request): array
    {
        return $request->validate([
            'keyword' => 'nullable|string|max:255',
            'startDate' => 'nullable|date_format:Y-m-d',
            'endDate' => 'nullable|date_format:Y-m-d' . ($request->filled('startDate') ? '|after_or_equal:startDate' : ''),
        ]);
    }

    /**
     * 创建 PayPal 账户记录。
     *
     * 请求字段（校验规则）：
     * - email：'required|email|max:255|unique:paypal_accounts,email'
     * - accountName：'required|string|max:255'
     * - addedDate：'nullable|date_format:Y-m-d'
     * - balance：'required|numeric|min:0|max:10000000|decimal:0,2'
     * - reviews：'required|integer|min:0|max:1000000'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为PayPal 账户的新增结果
     * @see PaypalService::create()
     */
    public function create(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validatedData = $request->validate([
            'email' => 'required|email|max:255|unique:paypal_accounts,email',
            'accountName' => 'required|string|max:255',
            'addedDate' => 'nullable|date_format:Y-m-d',
            'balance' => 'required|numeric|min:0|max:10000000|decimal:0,2',
            'reviews' => 'required|integer|min:0|max:1000000',
        ]);

        return AppResponse::success($this->paypalService->create($validatedData, (int) $request->attributes->get('auth_user')->id));
    }

    /**
     * 更新 PayPal 账户记录。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int  $id  PayPal 账户记录主键 ID
     * @param  string  $action  要执行的业务操作标识
     * @return JsonResponse 统一 JSON 响应；data 为PayPal 账户的更新结果
     * @see PaypalService::update()
     */
    public function update(
        Request $request,
        int $id,
        string $action,
    ): JsonResponse {
        $rules = ['version' => 'required|integer|min:1'];
        if ($action === 'review') {
            $rules['value'] = 'required|integer|min:0|max:1000000';
        } else {
            $rules['amount'] = 'required|numeric|' . ($action === 'withdrawal' ? 'gt:0' : 'min:0') . '|max:10000000|decimal:0,2';
        }
        if ($action === 'withdrawal') {
            $rules['date'] = 'required|date_format:Y-m-d|before_or_equal:today';
            $rules['source'] = 'nullable|string|max:2000';
        }

        return AppResponse::success($this->paypalService->update($id, $action, $request->validate($rules), (int) $request->attributes->get('auth_user')->id));
    }
}
