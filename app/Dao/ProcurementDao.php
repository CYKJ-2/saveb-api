<?php

namespace App\Dao;

use App\Models\ProcurementRemovedOrder;
use App\Models\ProcurementTask;
use App\Models\WarehouseRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 采购任务数据访问：封装模型查询与持久化操作。
 */
class ProcurementDao
{
    /**
     * 同一来源订单的建单和移除共用事务锁，避免并发生成隐藏任务。
     */
    public function lockSource(string $sourceKey): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['procurement:' . $sourceKey]);
    }

    /**
     * 读取记录集合。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProcurementTask>
     */
    public function all(): Collection
    {
        return ProcurementTask::with('warehouse')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 读取已移除来源标识。
     */
    public function removed(): array
    {
        return ProcurementRemovedOrder::pluck('order_id')->all();
    }

    /**
     * 加行锁读取记录。
     */
    public function lock(int $id): ProcurementTask
    {
        return ProcurementTask::with('warehouse')
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * 保存记录。
     */
    public function save(?ProcurementTask $task, array $data): ?ProcurementTask
    {
        $task ??= new ProcurementTask();
        $task
            ->fill($data)
            ->save();

        return $task->fresh();
    }

    /**
     * 移除记录。
     */
    public function remove(ProcurementTask $task): void
    {
        $task->delete();
    }

    /**
     * 记录已移除的来源订单。
     */
    public function hideOrder(string $id, int $actor): void
    {
        ProcurementRemovedOrder::firstOrCreate(['legacy_id' => 'api:' . $id], [
            'order_id' => $id,
            'removed_by' => $actor,
            'raw' => [],
        ]);
    }

    /**
     * 将采购记录交接至仓库。
     */
    public function handover(ProcurementTask $task): void
    {
        WarehouseRecord::firstOrCreate(
            ['procurement_task_id' => $task->id],
            [
                'fulfillment_status' => 'pending_inspection',
                'items' => $task->raw['products'] ?? [
                    [
                        'name' => $task->raw['productName'] ?? '',
                        'quantity' => 1,
                        'shippedQuantity' => 0,
                    ],
                ],
                'history' => [],
            ],
        );
    }
}
