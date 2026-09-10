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
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\WarehouseRecord> 仓库记录查询或计算结果集合；无匹配时为空集合
     */
    public function all(): Collection
    {
        return WarehouseRecord::with('task')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * 加行锁读取记录。
     *
     * @param  int  $id  仓库记录记录主键 ID
     * @return WarehouseRecord 仓库记录模型实例
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException 指定业务记录不存在
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
     * 保存仓库记录及其关联数据。
     *
     * @param  WarehouseRecord  $row  仓库记录单条记录
     * @param  array  $data  经过 Controller 校验的业务字段
     * @return void 无返回值；副作用见方法说明
     */
    public function save(WarehouseRecord $row, array $data): void
    {
        $row
            ->fill($data)
            ->save();
    }

    /**
     * 将仓库结果同步回采购状态，仅在状态变化时递增版本。
     *
     * @param  ProcurementTask  $task  采购任务模型
     * @param  string  $status  目标业务状态或查询状态
     * @return void 无返回值；副作用见方法说明
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
