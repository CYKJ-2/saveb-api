<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 仓库记录模型：定义数据表、字段转换及关联关系。
 *
 * @property int $id 主键
 * @property int $procurement_task_id FK → procurement_tasks.id（级联），UQ
 * @property string|null $fulfillment_status 履约状态
 * @property array $items 历史商品明细快照
 * @property array $history 历史状态轨迹
 * @property int|null $updated_by 最后修改用户
 * @property \Carbon\CarbonInterface $created_at 创建时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 * @property int $version 乐观锁版本号
 * @property-read \App\Models\ProcurementTask|null $task
 */
class WarehouseRecord extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'warehouse_records';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /**
     * 定义字段类型转换。
     *
     * @return array<string, string> 数据库字段名到 Eloquent 转换类型的映射
     */
    protected function casts(): array
    {
        return [
            'items' => 'array',
            'history' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * 关联采购任务。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\ProcurementTask, $this> 用于加载或继续约束该关联的 Eloquent 关系对象
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProcurementTask::class, 'procurement_task_id');
    }
}
