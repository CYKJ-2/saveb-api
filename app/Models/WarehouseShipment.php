<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 仓库发货单，对应 warehouse_shipments 表。
 *
 * @property string $id 主键
 * @property string $purchase_task_id FK → purchase_tasks.id
 * @property string $shipment_number 发货单号，唯一
 * @property string|null $carrier 承运商
 * @property string|null $tracking_number 物流单号
 * @property array $shipped_items 发货商品结构化清单
 * @property string $shipped_by_user_uuid FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $shipped_at 发货时间
 * @property \Carbon\CarbonInterface $created_at 创建时间（v4 新增）
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class WarehouseShipment extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'warehouse_shipments';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'shipped_items' => 'array',
        'shipped_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
