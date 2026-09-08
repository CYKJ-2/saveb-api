<?php

namespace App\Models;

/**
 * 物流跟踪事件，对应 shipment_tracking_events 表。
 *
 * @property int $id 主键
 * @property int|null $procurement_task_id FK → procurement_tasks.id（级联）
 * @property string $provider 物流数据提供方（UQ 组成）
 * @property string $tracking_number 物流单号
 * @property string|null $status 物流主状态
 * @property string|null $substatus 物流子状态
 * @property string|null $event_id 提供方事件标识（UQ 组成）
 * @property array $raw 提供方原始事件快照
 * @property \Carbon\CarbonInterface|null $occurred_at 事件发生时间
 * @property \Carbon\CarbonInterface $created_at 入库时间
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class ShipmentTrackingEvent extends BaseModel
{
    /** @var string 数据表名 */
    protected $table = 'shipment_tracking_events';

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'raw' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
