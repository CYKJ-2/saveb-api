<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use App\Dao\WarehouseDao;
use Illuminate\Support\Facades\DB;

/**
 * 仓库处理服务：处理业务规则、统计口径和事务。
 */
class WarehouseService
{
    public const STATUSES = [
        'pending_inspection',
        'ready_to_ship',
        'exchange_in_progress',
        'return_in_progress',
        'customer_confirm_pending',
        'partially_shipped',
        'shipped',
    ];

    /**
     * 注入 仓库记录处理所需的依赖。
     *
     * @param  WarehouseDao  $warehouseDao  仓库记录数据访问对象
     * @param  BusinessOperationLogDao  $businessOperationLogDao  业务操作日志数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private WarehouseDao $warehouseDao,
        private BusinessOperationLogDao $businessOperationLogDao,
    ) {
    }

    /**
     * 按筛选条件分页查询仓库记录。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 scope、keyword、status
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     * @see WarehouseDao::all()
     */
    public function listing(array $filters): array
    {
        $rows = [];
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->warehouseDao->all() as $warehouseRecord) {
            if (!$warehouseRecord->task) {
                continue;
            }
            $raw = $warehouseRecord->task->raw ?? [];
            $date = $raw['date'] ?? $warehouseRecord->task->created_at->toDateString();
            $recent = $date >= now('Asia/Shanghai')
                ->subDays(120)
                ->toDateString();
            if (($filters['scope'] ?? 'recent') === 'recent' && !$recent || ($filters['scope'] ?? '') === 'history' && $recent) {
                continue;
            }
            $data = [
                'id' => $warehouseRecord->id,
                'version' => $warehouseRecord->version,
                'orderId' => $warehouseRecord->task->order_id,
                'customer' => $raw['customer'] ?? '',
                'productName' => $raw['productName'] ?? '',
                'supplier' => $warehouseRecord->task->supplier,
                'trackingNumber' => $warehouseRecord->task->tracking_no,
                'date' => $date,
                'status' => $warehouseRecord->fulfillment_status,
                'items' => $warehouseRecord->items ?? [],
                'history' => $warehouseRecord->history ?? [],
            ];
            if (!empty($filters['keyword']) && mb_stripos(implode(' ', array_filter($data, 'is_scalar')), $filters['keyword']) === false) {
                continue;
            }
            $counts[$warehouseRecord->fulfillment_status] = ($counts[$warehouseRecord->fulfillment_status] ?? 0) + 1;
            if (!empty($filters['status']) && $warehouseRecord->fulfillment_status !== $filters['status']) {
                continue;
            }
            $rows[] = $data;
        }

        return \App\Common\PageResult::fromRows($rows, $filters) + [
            'statuses' => $counts,
            'allTotal' => array_sum($counts),
        ];
    }

    /**
     * 在同一事务内校验版本、更新仓库、同步采购并写入操作日志。
     *
     * @param  int  $id  仓库记录记录主键 ID
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 version、items、status、notes
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return array 仓库记录结果数组，包含 id 等字段
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see WarehouseDao::lock()
     * @see WarehouseDao::save()
     * @see WarehouseDao::syncTask()
     * @see BusinessOperationLogDao::record()
     */
    public function action(
        int $id,
        array $data,
        int $actor,
    ): array {
        return DB::transaction(function () use ($id, $data, $actor) {
            $warehouseRecord = $this->warehouseDao->lock($id);
            abort_if($warehouseRecord->version !== $data['version'], 409, '仓库记录已更新，请刷新');
            abort_unless($warehouseRecord->task, 422, '采购来源不可用');
            $items = $this->mergeItemUpdates($warehouseRecord->items ?? [], $data['items']);
            $status = $this->resolveFulfillmentStatus($items, $data['status']);
            $before = $warehouseRecord->toArray();
            $history = $warehouseRecord->history ?? [];
            $history[] = [
                'at' => now()->toIso8601String(),
                'actor' => $actor,
                'status' => $status,
                'notes' => $data['notes'] ?? '',
            ];
            $this->warehouseDao->save(
                $warehouseRecord,
                [
                    'items' => $items,
                    'fulfillment_status' => $status,
                    'history' => $history,
                    'updated_by' => $actor,
                    'version' => $warehouseRecord->version + 1,
                ],
            );
            $taskStatus = in_array($status, ['shipped', 'exchange_in_progress', 'return_in_progress', 'customer_confirm_pending']) ? $status : 'warehouse_arrived';
            $this->warehouseDao->syncTask($warehouseRecord->task, $taskStatus);
            $this->businessOperationLogDao->record('warehouse', (string) $id, 'action', $actor, $before, $warehouseRecord->toArray());

            return [
                'id' => $id,
                'version' => $warehouseRecord->version,
            ];
        });
    }

    /**
     * 校验质检和物流信息；累计发货数量不能倒退或超过采购数量。
     *
     * @param  array  $items  订单商品明细
     * @param  array  $updates  本次提交的商品质检与发货更新
     * @return array 合并质检、累计发货数量和物流信息后的商品列表
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     */
    private function mergeItemUpdates(array $items, array $updates): array
    {
        abort_if(count($items) !== count($updates), 422, '商品明细数量不一致');
        foreach ($updates as $index => $item) {
            abort_if($item['shippedQuantity'] > (int) ($items[$index]['quantity'] ?? 1), 422, '发货数量不能超过采购数量');
            abort_if($item['shippedQuantity'] < (int) ($items[$index]['shippedQuantity'] ?? 0), 422, '已发货数量不能减少');
            if ($item['shippedQuantity'] > 0) {
                abort_unless($item['inspection'] === 'passed' && !empty($item['outboundTracking']), 422, '发货前必须通过质检并填写出库物流单号');
            }
            $items[$index] = array_merge($items[$index], array_intersect_key($item, array_flip(['inspection', 'shippedQuantity', 'outboundTracking', 'notes'])));
        }

        return $items;
    }

    /**
     * 优先按实际发货数量推导状态，待发货状态要求全部商品质检通过。
     *
     * @param  array  $items  订单商品明细
     * @param  string  $status  目标业务状态或查询状态
     * @return string 标准化后的业务状态
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     */
    private function resolveFulfillmentStatus(array $items, string $status): string
    {
        $shipped = array_sum(array_column($items, 'shippedQuantity'));
        $quantity = array_sum(array_map(fn ($item) => (int) ($item['quantity'] ?? 1), $items));
        if ($shipped > 0) {
            $status = $shipped === $quantity ? 'shipped' : 'partially_shipped';
        } else {
            abort_if(in_array($status, ['shipped', 'partially_shipped']), 422, '请先记录商品发货数量');
        }
        if ($status === 'ready_to_ship') {
            foreach ($items as $item) {
                abort_unless(($item['inspection'] ?? '') === 'passed', 422, '全部商品质检通过后才能待发货');
            }
        }

        return $status;
    }
}
