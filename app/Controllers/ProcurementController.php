<?php

namespace App\Controllers;

use App\Common\AppResponse;
use App\Services\ProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 采购任务接口：负责参数校验、调用服务和封装响应。
 */
class ProcurementController
{
    public function __construct(private ProcurementService $procurementService)
    {
    }

    /**
     * 校验并整理筛选条件。
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'keyword' => 'nullable|string|max:255',
            'status' => 'nullable|in:' . implode(',', ProcurementService::STATUSES),
            'startDate' => 'nullable|date_format:Y-m-d',
            'endDate' => 'nullable|date_format:Y-m-d|after_or_equal:startDate',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'available_only' => 'sometimes|boolean',
        ]);
    }

    /**
     * 查询列表。
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->procurementService->listing($this->filters($request)));
    }

    /**
     * 按统计模块汇总数据。
     */
    public function statistics(Request $request): JsonResponse
    {
        return AppResponse::success($this->procurementService->statistics($this->filters($request)));
    }

    /**
     * 保存记录。
     */
    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'version' => ($id ? 'required' : 'sometimes') . '|integer|min:0',
            'sourceKey' => 'nullable|string|max:200',
            'products' => 'sometimes|array|min:1|max:100',
            'products.*.name' => 'required|string|max:2000',
            'products.*.quantity' => 'required|integer|min:1|max:10000',
            'productName' => 'required|string|max:2000',
            'quantity' => 'required|integer|min:1|max:10000',
            'purchaseStatus' => 'required|in:' . implode(',', ProcurementService::STATUSES),
            'supplier' => 'nullable|string|max:255',
            'cost' => 'nullable|numeric|min:0|max:10000000|decimal:0,2',
            'eta' => 'nullable|date_format:Y-m-d',
            'trackingNumber' => 'nullable|string|max:255',
            'trackingCarrier' => 'nullable|string|max:128',
            'trackingPhone' => 'nullable|string|max:64',
            'notes' => 'nullable|string|max:10000',
        ]);

        return AppResponse::success($this->procurementService->save($id, $data, (int) $request->attributes->get('auth_user')->id));
    }

    /**
     * 删除记录。
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $validatedData = $request->validate(['version' => 'required|integer|min:1']);
        $this->procurementService->remove($id, $validatedData['version'], (int) $request->attributes->get('auth_user')->id);

        return AppResponse::success();
    }

    /**
     * 移除尚未建立采购任务的来源订单。
     */
    public function destroySource(Request $request): JsonResponse
    {
        $validatedData = $request->validate(['sourceKey' => 'required|string|max:200']);
        $this->procurementService->removeSource(
            $validatedData['sourceKey'],
            (int) $request->attributes->get('auth_user')->id,
        );

        return AppResponse::success();
    }

    /**
     * 分页读取操作日志。
     */
    public function logs(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules());

        return AppResponse::success($this->procurementService->logs($validatedData['page'] ?? 1, $validatedData['per_page'] ?? 20));
    }

    /**
     * 导出数据。
     */
    public function export(Request $request): StreamedResponse
    {
        return \App\Common\CsvResponse::download(
            $this->procurementService->exportRows($this->filters($request)),
            [
                'createTime' => 'Order Time', 'orderId' => 'Order ID', 'paypalOrderId' => 'PayPal Order ID',
                'customer' => 'Customer', 'site' => 'Source Site', 'amountOriginal' => 'Amount', 'currency' => 'Currency',
                'productName' => 'Product', 'supplier' => 'Supplier', 'purchaser' => 'Purchaser', 'cost' => 'Cost',
                'eta' => 'Expected Arrival', 'trackingCarrier' => 'Carrier', 'trackingNumber' => 'Tracking',
                'trackingPhone' => 'Tracking Phone', 'deliveryStatus' => 'Delivery Status',
                'purchaseStatus' => 'Purchase Status', 'priority' => 'Priority', 'notes' => 'Notes',
            ],
            'purchase_orders_' . now('Asia/Shanghai')->toDateString() . '.csv',
        );
    }

    /**
     * 导出操作日志。
     */
    public function exportLogs(): StreamedResponse
    {
        return \App\Common\CsvResponse::download(
            $this->procurementService->exportLogs(),
            ['created_at' => 'Operation Time', 'action' => 'Action', 'entity' => 'Entity', 'entity_id' => 'Record ID'],
            'procurement-audit.csv',
        );
    }
}
