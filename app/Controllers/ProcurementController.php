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
    /**
     * 注入 采购任务处理所需的依赖。
     *
     * @param  ProcurementService  $procurementService  采购任务业务服务
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(private ProcurementService $procurementService)
    {
    }

    /**
     * 校验并整理筛选条件。
     *
     * 请求字段（校验规则）：
     * - keyword：'nullable|string|max:255'
     * - status：'nullable|in:' . implode(',', ProcurementService::STATUSES)
     * - startDate：'nullable|date_format:Y-m-d'
     * - endDate：'nullable|date_format:Y-m-d|after_or_equal:startDate'
     * - page：'nullable|integer|min:1'
     * - per_page：'sometimes|integer|min:1|max:100'
     * - available_only：'sometimes|boolean'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return array 通过校验的采购筛选与分页参数
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
     * 查询采购任务列表。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含采购任务列表及相应分页信息
     * @see ProcurementService::listing()
     */
    public function index(Request $request): JsonResponse
    {
        return AppResponse::success($this->procurementService->listing($this->filters($request)));
    }

    /**
     * 按统计模块汇总数据。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为采购任务的统计汇总
     * @see ProcurementService::statistics()
     */
    public function statistics(Request $request): JsonResponse
    {
        return AppResponse::success($this->procurementService->statistics($this->filters($request)));
    }

    /**
     * 保存采购任务及其关联数据。
     *
     * 请求字段（校验规则）：
     * - version：($id ? 'required' : 'sometimes') . '|integer|min:0'
     * - sourceKey：'nullable|string|max:200'
     * - products：'sometimes|array|min:1|max:100'
     * - products.*.name：'required|string|max:2000'
     * - products.*.quantity：'required|integer|min:1|max:10000'
     * - productName：'required|string|max:2000'
     * - quantity：'required|integer|min:1|max:10000'
     * - purchaseStatus：'required|in:' . implode(',', ProcurementService::STATUSES)
     * - supplier：'nullable|string|max:255'
     * - cost：'nullable|numeric|min:0|max:10000000|decimal:0,2'
     * - eta：'nullable|date_format:Y-m-d'
     * - trackingNumber：'nullable|string|max:255'
     * - trackingCarrier：'nullable|string|max:128'
     * - trackingPhone：'nullable|string|max:64'
     * - notes：'nullable|string|max:10000'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int|null  $id  采购任务记录主键 ID；null 表示新增
     * @return JsonResponse 统一 JSON 响应；data 为采购任务的保存结果
     * @see ProcurementService::save()
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
     * 删除采购任务记录。
     *
     * 请求字段（校验规则）：
     * - version：'required|integer|min:1'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @param  int  $id  采购任务记录主键 ID
     * @return JsonResponse 操作成功的统一 JSON 响应
     * @see ProcurementService::remove()
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $validatedData = $request->validate(['version' => 'required|integer|min:1']);
        $this->procurementService->remove($id, $validatedData['version'], (int) $request->attributes->get('auth_user')->id);

        return AppResponse::success();
    }

    /**
     * 移除尚未建立采购任务的来源订单。
     *
     * 请求字段（校验规则）：
     * - sourceKey：'required|string|max:200'
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 为采购任务的业务结果
     * @see ProcurementService::removeSource()
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
     *
     * 分页字段：page 从 1 开始；per_page 为 1–100，默认 20。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return JsonResponse 统一 JSON 响应；data 包含采购日志、operator 操作者名称及分页信息
     * @see ProcurementService::logs()
     */
    public function logs(Request $request): JsonResponse
    {
        $validatedData = $request->validate(\App\Common\PageResult::rules());

        return AppResponse::success($this->procurementService->logs($validatedData['page'] ?? 1, $validatedData['per_page'] ?? 20));
    }

    /**
     * 导出采购任务的完整筛选结果。
     *
     * @param  Request  $request  当前 HTTP 请求；查询或表单参数由本方法校验，登录上下文由认证中间件注入
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see ProcurementService::exportRows()
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
     * 导出操作日志，操作者名称与日志列表使用相同规则。
     *
     * @return StreamedResponse CSV 流式下载响应，包含表头及导出数据
     * @see ProcurementService::exportLogs()
     */
    public function exportLogs(): StreamedResponse
    {
        return \App\Common\CsvResponse::download(
            $this->procurementService->exportLogs(),
            ['created_at' => 'Operation Time', 'action' => 'Action', 'entity' => 'Entity', 'entity_id' => 'Record ID', 'operator' => 'Operator'],
            'procurement-audit.csv',
        );
    }
}
