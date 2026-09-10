<?php

namespace App\Services;

use App\Dao\BusinessOperationLogDao;
use App\Dao\ProcurementDao;
use App\Models\BusinessOperationLog;
use App\Models\ProcurementTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 采购任务服务：处理业务规则、统计口径和事务。
 */
class ProcurementService
{
    public const STATUSES = [
        'pending_purchase',
        'supplier_shipping_pending',
        'warehouse_arrived',
        'exchange_in_progress',
        'return_in_progress',
        'customer_confirm_pending',
        'shipped',
    ];

    /**
     * 注入 采购任务处理所需的依赖。
     *
     * @param  ProcurementDao  $procurementDao  采购任务数据访问对象
     * @param  OrderManagementService  $orderManagementService  订单管理业务服务
     * @param  BusinessOperationLogDao  $businessOperationLogDao  业务操作日志数据访问对象
     * @return void 无返回值；完成依赖初始化
     */
    public function __construct(
        private ProcurementDao $procurementDao,
        private OrderManagementService $orderManagementService,
        private BusinessOperationLogDao $businessOperationLogDao,
    ) {
    }

    /**
     * 分页读取操作日志。
     *
     * @param  int  $page  页码，从 1 开始
     * @param  int  $perPage  每页条数；默认 20
     * @return LengthAwarePaginator 采购日志分页器，包含操作人名称、当前页记录和分页信息
     * @see BusinessOperationLogDao::procurementLogs()
     */
    public function logs(int $page, int $perPage = 20): LengthAwarePaginator
    {
        return $this->businessOperationLogDao->procurementLogs($page, $perPage)
            ->through(fn (BusinessOperationLog $log) => $this->presentLog($log));
    }

    /**
     * 读取全部操作日志。
     *
     * @return iterable 按需迭代的采购任务记录，供逐条处理或导出
     * @see BusinessOperationLogDao::all()
     */
    public function allLogs(): iterable
    {
        return $this->businessOperationLogDao->all('procurement');
    }

    /**
     * 按原采购 CSV 整理字段，状态名称跟随导出语言。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @return iterable 按需迭代的采购任务记录，供逐条处理或导出
     */
    public function exportRows(array $filters): iterable
    {
        $locale = \App\Common\ExportHeaders::locale();
        foreach ($this->rows($filters) as $row) {
            $row['createTime'] ??= $row['date'] ?? '';
            $row['amountOriginal'] ??= $row['amount'] ?? '';
            $row['currency'] ??= 'USD';
            $row['purchaser'] ??= '';
            $row['deliveryStatus'] = \App\Common\ExportValue::status($row['deliveryStatus'] ?? 'unknown', $locale);
            $row['purchaseStatus'] = \App\Common\ExportValue::status($row['purchaseStatus'], $locale);
            $row['priority'] = \App\Common\ExportValue::status($row['priority'] ?? 'normal', $locale);
            yield $row;
        }
    }

    /**
     * 原采购日志同时包含采购任务和仓库操作。
     *
     * @return iterable 包含操作人名称的采购及仓库日志，供流式导出
     * @see BusinessOperationLogDao::procurementExportLogs()
     */
    public function exportLogs(): iterable
    {
        foreach ($this->businessOperationLogDao->procurementExportLogs() as $log) {
            yield $this->presentLog($log) + ['entity' => $log->module === 'warehouse' ? 'warehouse_record' : 'procurement_task'];
        }
    }

    /**
     * 为列表与导出统一补充操作者名称，保留原始用户 ID 供历史追溯。
     *
     * @param  BusinessOperationLog  $log  已关联操作人资料的采购或仓库日志
     * @return array 原始日志及 operator；优先显示名称，其次登录账号，用户缺失时使用 #ID，无 ID 时为空
     */
    private function presentLog(BusinessOperationLog $log): array
    {
        $operator = trim((string) $log->operator_display_name)
            ?: trim((string) $log->operator_name)
            ?: ($log->actor_user_id ? '#' . $log->actor_user_id : '');

        return $log->toArray() + ['operator' => $operator];
    }

    /**
     * 合并来源订单与采购任务，先筛选再交由列表分页或全量导出。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 available_only、status、keyword、startDate、endDate
     * @return array 合并并筛选后的来源订单与采购任务列表，尚未分页
     * @see ProcurementDao::removed()
     * @see OrderManagementService::rows()
     * @see ProcurementDao::all()
     */
    public function rows(array $filters): array
    {
        $rows = [];
        $removed = array_flip($this->procurementDao->removed());
        foreach ($this->orderManagementService->rows(['orderStatus' => 'completed']) as $order) {
            $key = $order['kind'] . ':' . $order['id'];
            if (isset($removed[$key])) {
                continue;
            }
            $rows[$key] = [
                'id' => null,
                'sourceKey' => $key,
                'orderId' => $order['orderId'],
                'customer' => $order['customerFullName'],
                'date' => $order['date'],
                'site' => $order['clientSite'],
                'amount' => $order['amountUsd'],
                'amountOriginal' => $order['amount'],
                'currency' => $order['currency'],
                'createTime' => $order['createTime'],
                'paypalOrderId' => $order['paypalOrderId'],
                'productName' => $order['productName'],
                'quantity' => $order['items'],
                'purchaseStatus' => 'pending_purchase',
                'version' => 0,
            ];
            $rows[$key]['products'] = $order['products'] ?? [
                [
                    'name' => $order['productName'],
                    'quantity' => max(1, $order['items']),
                ],
            ];
        }
        foreach ($this->procurementDao->all() as $task) {
            $raw = $task->raw ?? [];
            $key = $raw['sourceKey'] ?? 'task:' . $task->id;
            if (isset($removed[$key])) {
                continue;
            }
            $rows[$key] = array_merge(
                $rows[$key] ?? [],
                $raw,
                [
                    'id' => $task->id,
                    'sourceKey' => $key,
                    'orderId' => $task->order_id,
                    'purchaseStatus' => $task->purchase_status,
                    'supplier' => $task->supplier,
                    'cost' => $task->cost,
                    'eta' => $task->eta,
                    'trackingNumber' => $task->tracking_no,
                    'notes' => $task->notes,
                    'version' => $task->version,
                    'date' => $raw['date'] ?? $task->created_at->toDateString(),
                ],
            );
            // 来源订单的历史 JSON 快照可能带有金额尾数；成本字段已由模型保留两位。
            if (isset($rows[$key]['amount']) && is_numeric($rows[$key]['amount'])) {
                $amount = $rows[$key]['amount'];
                $rows[$key]['amount'] = is_string($amount) ? number_format((float) $amount, 2, '.', '') : round($amount, 2);
            }
            $rows[$key]['warehouseId'] = $task->warehouse?->id;
        }

        return array_values(array_filter(
            $rows,
            function ($procurementRow) use ($filters) {
                if (!empty($filters['available_only']) && !empty($procurementRow['id'])) {
                    return false;
                }
                if (!empty($filters['status']) && $procurementRow['purchaseStatus'] !== $filters['status']) {
                    return false;
                }
                if (!empty($filters['keyword']) && mb_stripos(implode(' ', array_filter($procurementRow, 'is_scalar')), $filters['keyword']) === false) {
                    return false;
                }

                return (empty($filters['startDate']) || $procurementRow['date'] >= $filters['startDate']) && (empty($filters['endDate']) || $procurementRow['date'] <= $filters['endDate']);
            },
        ));
    }

    /**
     * 按筛选条件分页查询采购任务。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件
     * @return array 当前页记录及分页信息；汇总字段按业务方法计算
     */
    public function listing(array $filters): array
    {
        $rows = $this->rows($filters);
        usort($rows, fn ($firstRow, $secondRow) => strcmp($secondRow['date'], $firstRow['date']));

        return \App\Common\PageResult::fromRows($rows, $filters);
    }

    /**
     * 按统计模块汇总数据。
     *
     * @param  array  $filters  当前业务模块的筛选及分页条件；本方法读取 status
     * @return array 采购任务结果数组；返回字段：total、statuses
     */
    public function statistics(array $filters): array
    {
        unset($filters['status']);
        $rows = $this->rows($filters);
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $counts[$row['purchaseStatus']] = ($counts[$row['purchaseStatus']] ?? 0) + 1;
        }

        return [
            'total' => count($rows),
            'statuses' => $counts,
        ];
    }

    /**
     * 保存采购任务及其关联数据。
     *
     * @param  int|null  $id  采购任务记录主键 ID
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 version、trackingNumber、trackingCarrier、trackingPhone、products、productName、quantity、purchaseStatus、supplier、cost、eta、notes
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return array 采购任务结果数组，包含 id 等字段
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see ProcurementDao::lock()
     * @see ProcurementDao::save()
     * @see ProcurementDao::handover()
     * @see BusinessOperationLogDao::record()
     */
    public function save(
        ?int $id,
        array $data,
        int $actor,
    ): array {
        return DB::transaction(function () use ($id, $data, $actor) {
            $task = $id ? $this->procurementDao->lock($id) : null;
            $before = $task?->toArray();
            if ($task) {
                abort_if($task->version !== $data['version'], 409, '采购记录已更新，请刷新');
            }
            $raw = $this->sourceSnapshot($task, $data);
            if ($task && (
                ($data['trackingNumber'] ?? '') !== ($task->tracking_no ?? '')
                || ($data['trackingCarrier'] ?? '') !== ($raw['trackingCarrier'] ?? '')
                || ($data['trackingPhone'] ?? '') !== ($raw['trackingPhone'] ?? '')
            )) {
                // 更换查询条件后不能继续显示旧运单的签收状态或复用旧提供方 ID。
                foreach (['trackingProviderId', 'trackingQueriedNumber', 'trackingLastCheckedAt', 'trackingUpdatedAt', 'trackingCheckpoint', 'trackingError', 'deliveredAt'] as $key) {
                    unset($raw[$key]);
                }
                $raw['deliveryStatus'] = 'pending';
            }
            $products = array_map(
                fn ($product) => [
                    'name' => $product['name'],
                    'quantity' => (int) $product['quantity'],
                    'shippedQuantity' => 0,
                ],
                $data['products'] ?? [
                    [
                        'name' => $data['productName'],
                        'quantity' => $data['quantity'],
                    ],
                ],
            );
            $data['productName'] = implode(' / ', array_column($products, 'name'));
            $data['quantity'] = array_sum(array_column($products, 'quantity'));
            $this->validateHandoverChanges($task, $data, $raw, $products);
            foreach (['productName', 'quantity', 'trackingCarrier', 'trackingPhone'] as $key) {
                if (array_key_exists($key, $data)) {
                    $raw[$key] = $data[$key];
                }
            }
            if (!$task?->warehouse) {
                $raw['products'] = $products;
            }
            $record = $this->procurementDao->save(
                $task,
                [
                    'order_id' => $task?->order_id ?? $raw['orderId'] ?? 'PUR-' . strtoupper(Str::random(10)),
                    'legacy_id' => $task?->legacy_id ?? 'api-' . Str::uuid(),
                    'purchase_status' => $data['purchaseStatus'],
                    'supplier' => $data['supplier'] ?? null,
                    'cost' => $data['cost'] ?? null,
                    'eta' => $data['eta'] ?? null,
                    'tracking_no' => $data['trackingNumber'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'raw' => $raw,
                    'created_by' => $task?->created_by ?? $actor,
                    'version' => ($task?->version ?? 0) + 1,
                ],
            );
            if ($record->purchase_status === 'warehouse_arrived') {
                $this->procurementDao->handover($record);
            }
            $this->businessOperationLogDao->record('procurement', (string) $record->id, $id ? 'update' : 'create', $actor, $before, $record->toArray());

            return [
                'id' => $record->id,
                'version' => $record->version,
            ];
        });
    }

    /**
     * 移除采购任务记录。
     *
     * @param  int  $id  采购任务记录主键 ID
     * @param  int  $version  客户端读取的乐观锁版本，用于检测并发修改
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return void 无返回值；副作用见方法说明
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see ProcurementDao::lock()
     * @see BusinessOperationLogDao::record()
     * @see ProcurementDao::hideOrder()
     * @see ProcurementDao::remove()
     */
    public function remove(
        int $id,
        int $version,
        int $actor,
    ): void {
        DB::transaction(function () use ($id, $version, $actor) {
            $task = $this->procurementDao->lock($id);
            abort_if($task->version !== $version, 409, '采购记录已更新');
            abort_if($task->warehouse !== null, 422, '已交接仓库的记录不能删除');
            $this->businessOperationLogDao->record('procurement', (string) $id, 'delete', $actor, $task->toArray(), null);
            if (!empty($task->raw['sourceKey'])) {
                $this->procurementDao->hideOrder($task->raw['sourceKey'], $actor);
            }
            $this->procurementDao->remove($task);
        });
    }

    /**
     * 未建采购任务的订单直接移出采购列表，保留原销售订单。
     *
     * @param  string  $sourceKey  来源订单唯一标识
     * @param  int  $actor  当前操作用户的主键 ID，用于授权校验或操作记录
     * @return void 无返回值；副作用见方法说明
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see ProcurementDao::lockSource()
     * @see ProcurementDao::hideOrder()
     * @see BusinessOperationLogDao::record()
     */
    public function removeSource(string $sourceKey, int $actor): void
    {
        DB::transaction(function () use ($sourceKey, $actor): void {
            $this->procurementDao->lockSource($sourceKey);
            $source = collect($this->rows([]))->firstWhere('sourceKey', $sourceKey);
            abort_unless($source, 422, '来源订单不存在');
            abort_if($source['id'] !== null, 409, '该订单已有采购记录，请刷新');

            $this->procurementDao->hideOrder($sourceKey, $actor);
            $this->businessOperationLogDao->record('procurement', $sourceKey, 'delete', $actor, $source, null);
        });
    }

    /**
     * 创建来源采购单时串行检查重复订单，并保留来源订单快照。
     *
     * @param  ProcurementTask|null  $task  采购任务模型；null 表示不存在或尚未创建
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 sourceKey
     * @return array 已关联任务的原始快照，或经重复检查的来源订单快照
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     * @see ProcurementDao::lockSource()
     * @see ProcurementDao::all()
     */
    private function sourceSnapshot(?ProcurementTask $task, array $data): array
    {
        $raw = $task?->raw ?? [];
        if (!$task && !empty($data['sourceKey'])) {
            $this->procurementDao->lockSource($data['sourceKey']);
            foreach ($this->procurementDao->all() as $existing) {
                abort_if(($existing->raw['sourceKey'] ?? '') === $data['sourceKey'], 409, '该订单已有采购记录，请刷新');
            }
            $source = collect($this->rows([]))->firstWhere('sourceKey', $data['sourceKey']);
            abort_unless($source, 422, '来源订单不存在');
            $raw = $source;
        }

        return $raw;
    }

    /**
     * 已交接仓库后冻结采购商品和状态，由仓库流程继续处理。
     *
     * @param  ProcurementTask|null  $task  采购任务模型；null 表示不存在或尚未创建
     * @param  array  $data  经过 Controller 校验的业务字段；本方法读取 purchaseStatus
     * @param  array  $raw  来源系统保留的原始业务快照；本方法读取 products
     * @param  array  $products  采购商品明细
     * @return void 无返回值；副作用见方法说明
     * @throws \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface 业务校验、授权或资源可用性检查未通过
     */
    private function validateHandoverChanges(
        ?ProcurementTask $task,
        array $data,
        array $raw,
        array $products,
    ): void {
        if ($task?->warehouse) {
            foreach (['productName', 'quantity'] as $key) {
                abort_if(isset($data[$key]) && (string) $data[$key] !== (string) ($raw[$key] ?? ''), 422, '交接仓库后不能修改商品或采购数量');
            }
            abort_if($products !== ($raw['products'] ?? []), 422, '交接仓库后不能修改商品明细');
            abort_if($data['purchaseStatus'] !== $task->purchase_status, 422, '交接后的状态请在仓库管理中更新');
        } else {
            abort_if($data['purchaseStatus'] === 'shipped', 422, '请先交接仓库并完成质检、发货');
        }
    }
}
