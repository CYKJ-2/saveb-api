<?php

namespace App\Dao;

use App\Models\ProcurementTask;
use App\Models\WarehouseRecord;
use Illuminate\Database\Eloquent\Collection;

/**
 * 仓库处理数据访问：封装模型查询与持久化操作。
 */
class WarehouseDao
{
    /**
     * 读取记录集合。
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\WarehouseRecord>
     */
    public function all(): Collection
    {
        return WarehouseRecord::with('task')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 加行锁读取记录。
     */
    public function lock(int $id): WarehouseRecord
    {
        $taskId = WarehouseRecord::whereKey($id)->firstOrFail()->procurement_task_id;
        // Match procurement's lock order to avoid deadlocks during simultaneous edits.
        $task = ProcurementTask::whereKey($taskId)
            ->lockForUpdate()
            ->first();

        return WarehouseRecord::whereKey($id)
            ->lockForUpdate()
            ->firstOrFail()
            ->setRelation('task', $task);
    }

    /**
     * 保存记录。
     */
    public function save(WarehouseRecord $row, array $data): void
    {
        $row
            ->fill($data)
            ->save();
    }

    /**
     * 将仓库结果同步回采购状态，仅在状态变化时递增版本。
     */
    public function syncTask(ProcurementTask $task, string $status): void
    {
        if ($task->purchase_status !== $status) {
            $task
                ->fill([
                    'purchase_status' => $status,
                    'version' => $task->version + 1,
                ])
                ->save();
        }
    }
}
