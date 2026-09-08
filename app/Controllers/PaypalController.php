<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Common\CsvResponse;
use App\Services\PaypalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PayPal 账户接口：负责参数校验、调用服务和封装响应。
 */
class PaypalController
{
    public function __construct(private PaypalService $paypalService)
    {
    }

    /**
     * 导出数据。
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
     * 查询列表。
     */
    public function index(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules() + ['keyword' => 'nullable|string|max:255', 'sort' => 'sometimes|in:latestIncomingAt,received,balance,reviews,withdrawn', 'threshold' => 'sometimes|numeric|min:0']);

        return AppResponse::success($this->paypalService->page($validatedData));
    }

    /**
     * 查询关联订单。
     */
    public function orders(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules() + ['email' => 'required|email|max:255']);

        return AppResponse::success($this->paypalService->orderPage($validatedData));
    }

    /**
     * 查询提款记录与范围内合计。
     */
    public function withdrawals(Request $request): JsonResponse
    {
        return AppResponse::success($this->paypalService->withdrawalPage($this->dateFilters($request) + $request->validate(\App\Common\PageResult::rules())));
    }

    /**
     * 查询按日或按月汇总的提款金额。
     */
    public function statistics(Request $request): JsonResponse
    {
        $filters = $this->dateFilters($request);
        $filters += $request->validate(['mode' => 'required|in:daily,monthly']);

        return AppResponse::success($this->paypalService->withdrawalStatistics($filters));
    }

    /**
     * 导出当前账户的收款订单。
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
     * 导出账户修改日志。
     */
    public function exportLogs(Request $request): StreamedResponse
    {
        $data = $request->validate(['locale' => 'nullable|in:zh-CN,en-US']);
        $english = ($data['locale'] ?? '') === 'en-US';

        return CsvResponse::download($this->paypalService->changeLogs(), [
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
     */
    public function exportWithdrawals(Request $request): StreamedResponse
    {
        return CsvResponse::download($this->paypalService->withdrawals($this->dateFilters($request))['rows'], [
            'date' => 'Date', 'accountName' => 'Account Name', 'email' => 'Email',
            'amount' => 'Withdrawal Amount', 'source' => 'Source',
        ], 'paypal-withdrawals.csv');
    }

    /** 校验可选日期范围和账户关键词，列表及导出共用。 */
    private function dateFilters(Request $request): array
    {
        return $request->validate([
            'keyword' => 'nullable|string|max:255',
            'startDate' => 'nullable|date_format:Y-m-d',
            'endDate' => 'nullable|date_format:Y-m-d' . ($request->filled('startDate') ? '|after_or_equal:startDate' : ''),
        ]);
    }

    /**
     * 创建记录。
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
     * 更新记录。
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
