<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * 仓库收货单，对应 warehouse_receipts 表。
 *
 * @property string $id 主键
 * @property string $purchase_task_id FK → purchase_tasks.id
 * @property string $receipt_number 收货单号，唯一
 * @property string $inspection_status pending / passed / failed / partial（CHECK）
 * @property array $received_items 实收商品结构化清单
 * @property string $received_by_user_uuid FK → users.entity_uuid
 * @property \Carbon\CarbonInterface $received_at 收货时间
 * @property \Carbon\CarbonInterface $created_at 创建时间（v4 新增）
 * @property \Carbon\CarbonInterface $updated_at 更新时间（v4 新增）
 * @property \Carbon\CarbonInterface|null $deleted_at 软删除（v4 新增）
 */
class WarehouseReceipt extends BaseModel
{
    use HasUuids;

    /** @var string 数据表名 */
    protected $table = 'warehouse_receipts';

    /** @var string 主键类型 */
    protected $keyType = 'string';

    /** @var bool 是否使用自增主键 */
    public $incrementing = false;

    /** @var array<int, string> 批量赋值保护字段；写入参数由 Service 显式组装 */
    protected $guarded = [];

    /** @var array<string, string> 字段类型转换 */
    protected $casts = [
        'received_items' => 'array',
        'received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}
